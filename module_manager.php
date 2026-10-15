<?php
declare(strict_types=1);

function moduleNavigation(PDO $pdo,string $globalPrefix,int $userId,string $users,string $members,string $groups,string $registry,string $frontends,string $groupFrontends):array
{
    $rows=$pdo->query("SELECT id,slug,title FROM $registry ORDER BY title")->fetchAll();
    if(!$rows)return [];
    $stmt=$pdo->prepare("SELECT group_id FROM $members WHERE user_id=? ORDER BY group_id");
    $stmt->execute([$userId]);
    $groupIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    $items=[];
    foreach($rows as $module){
        $stmt=$pdo->prepare("SELECT frontend_id,name,file_name FROM $frontends WHERE module_id=? ORDER BY frontend_id");
        $stmt->execute([$module['id']]);
        $available=$stmt->fetchAll();
        if(!$available)continue;
        $selected=$available[0];
        foreach($groupIds as $groupId){
            $pick=$pdo->prepare("SELECT f.frontend_id,f.name,f.file_name FROM $groupFrontends gf JOIN $frontends f ON f.module_id=gf.module_id AND f.frontend_id=gf.frontend_id WHERE gf.group_id=? AND gf.module_id=?");
            $pick->execute([$groupId,$module['id']]);
            $choice=$pick->fetch();
            if($choice){$selected=$choice;break;}
        }
        $items[]=['slug'=>$module['slug'],'title'=>$module['title'],'frontend_id'=>(int)$selected['frontend_id']];
    }
    return $items;
}

function moduleManifest(string $directory):array
{
    $path=$directory.'/module.json';
    if(!is_file($path)||filesize($path)>65536)throw new RuntimeException('Das Paket benötigt eine gültige module.json.');
    $manifest=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($manifest))throw new RuntimeException('module.json muss ein JSON-Objekt enthalten.');
    $slug=(string)($manifest['slug']??'');
    $title=trim((string)($manifest['name']??''));
    $version=(string)($manifest['version']??'');
    $dbPrefix=(string)($manifest['db_prefix']??'');
    if(!preg_match('/^[a-z][a-z0-9_-]{1,49}$/',$slug))throw new RuntimeException('Der Modulschlüssel muss mit einem Kleinbuchstaben beginnen und 2–50 Zeichen lang sein.');
    if($title===''||mb_strlen($title)>120)throw new RuntimeException('Der Modulname muss 1–120 Zeichen lang sein.');
    if(!preg_match('/^[0-9]+\\.[0-9]+\\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/',$version))throw new RuntimeException('Die Modulversion muss das Format 1.0.0 haben.');
    if(!preg_match('/^[a-z][a-z0-9]{0,2}$/',$dbPrefix))throw new RuntimeException('db_prefix muss aus 1–3 Kleinbuchstaben oder Zahlen bestehen und mit einem Buchstaben beginnen.');
    foreach(['install.php','update.php','delete.php'] as $required)if(!is_file($directory.'/'.$required))throw new RuntimeException('Im Paket fehlt '.$required.'.');
    $frontends=$manifest['frontends']??null;
    if(!is_array($frontends)||count($frontends)<1||count($frontends)>20)throw new RuntimeException('Das Modul muss 1–20 Frontends auflisten.');
    $seen=[];
    foreach($frontends as &$frontend){
        if(!is_array($frontend))throw new RuntimeException('Frontend-Einträge müssen Objekte sein.');
        $id=filter_var($frontend['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        $name=trim((string)($frontend['name']??''));
        $file=(string)($frontend['file']??'');
        if($id===false||$id===null||$name===''||mb_strlen($name)>100)throw new RuntimeException('Jedes Frontend braucht eine gültige positive ID und einen Namen.');
        if(!preg_match('/^frontend_([0-9]+)_([a-z0-9_-]+)\\.php$/',$file,$matches)||(int)$matches[1]!==$id)throw new RuntimeException('Frontend-Dateien müssen frontend_ID_name.php heißen und zur ID in module.json passen.');
        if(!is_file($directory.'/'.$file))throw new RuntimeException('Frontend-Datei fehlt: '.$file);
        if(isset($seen[$id]))throw new RuntimeException('Frontend-IDs dürfen nicht doppelt vorkommen.');
        $seen[$id]=true;
        $frontend=['id'=>(int)$id,'name'=>$name,'file'=>$file];
    }
    unset($frontend);
    usort($frontends,static fn($a,$b)=>$a['id']<=>$b['id']);
    $manifest['slug']=$slug;$manifest['name']=$title;$manifest['version']=$version;$manifest['db_prefix']=$dbPrefix;$manifest['frontends']=$frontends;
    return $manifest;
}

function extractModulePackage(string $archive,string $stage):array
{
    if(!class_exists(ZipArchive::class))throw new RuntimeException('PHP-Erweiterung ZipArchive fehlt.');
    $zip=new ZipArchive();
    if($zip->open($archive)!==true)throw new RuntimeException('Die ZIP-Datei konnte nicht geöffnet werden.');
    if($zip->numFiles<4||$zip->numFiles>100){$zip->close();throw new RuntimeException('Das ZIP-Paket muss 4–100 Dateien enthalten.');}
    if(!mkdir($stage,0700,true)){$zip->close();throw new RuntimeException('Temporärer Modulordner konnte nicht angelegt werden.');}
    $total=0;$paths=[];
    try{
        for($i=0;$i<$zip->numFiles;$i++){
            $stat=$zip->statIndex($i);
            if(!$stat)throw new RuntimeException('ZIP-Eintrag kann nicht gelesen werden.');
            $name=(string)$stat['name'];
            if(str_ends_with($name,'/'))continue;
            if($name===''||str_starts_with($name,'/')||str_contains($name,chr(92))||preg_match('/^[A-Za-z]:/',$name)||!preg_match('/^[A-Za-z0-9_.\\/-]+$/',$name))throw new RuntimeException('Das ZIP enthält einen ungültigen Dateipfad.');
            foreach(explode('/',$name) as $part)if($part===''||$part==='.'||$part==='..'||str_starts_with($part,'.'))throw new RuntimeException('Das ZIP enthält einen gesperrten Dateipfad.');
            if(isset($paths[$name]))throw new RuntimeException('Das ZIP enthält doppelte Dateipfade.');
            $paths[$name]=true;
            $size=(int)$stat['size'];$total+=$size;
            if($size>2*1024*1024||$total>20*1024*1024)throw new RuntimeException('Das Paket überschreitet die Dateigrößenbegrenzung.');
            $opsys=0;$attributes=0;
            if($zip->getExternalAttributesIndex($i,$opsys,$attributes)&&$opsys===ZipArchive::OPSYS_UNIX&&(($attributes>>16)&0170000)===0120000)throw new RuntimeException('Symlinks sind in Modulpaketen nicht erlaubt.');
            $destination=$stage.'/'.$name;
            $parent=dirname($destination);
            if(!is_dir($parent)&&!mkdir($parent,0700,true))throw new RuntimeException('Ordner im Modulpaket konnten nicht angelegt werden.');
            $input=$zip->getStream($name);
            if(!$input)throw new RuntimeException('Datei im ZIP kann nicht geöffnet werden.');
            $output=fopen($destination,'xb');
            if(!$output){fclose($input);throw new RuntimeException('Datei im Modulpaket kann nicht geschrieben werden.');}
            stream_copy_to_stream($input,$output);fclose($input);fclose($output);chmod($destination,0600);
        }
        $zip->close();
        return moduleManifest($stage);
    }catch(Throwable $exception){$zip->close();removeModuleTree($stage);throw $exception;}
}

function removeModuleTree(string $directory):void
{
    if(!is_dir($directory))return;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry){$entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname());}
    rmdir($directory);
}

function runModuleHook(string $moduleDirectory,string $hook,object $moduleContext):void
{
    $path=$moduleDirectory.'/'.$hook;
    if(!is_file($path))throw new RuntimeException('Modul-Hook fehlt: '.$hook);
    $runner=static function(string $__path,object $moduleContext){return include $__path;};
    ob_start();
    try{$result=$runner($path,$moduleContext);ob_end_clean();}
    catch(Throwable $exception){ob_end_clean();throw $exception;}
    if($result===false)throw new RuntimeException($hook.' hat einen Fehler gemeldet.');
}

function moduleContext(PDO $pdo,string $globalPrefix,array $manifest,array $user,?string $oldVersion=null):object
{
    return (object)[
        'pdo'=>$pdo,
        'tablePrefix'=>$globalPrefix.$manifest['db_prefix'].'_',
        'globalPrefix'=>$globalPrefix,
        'slug'=>$manifest['slug'],
        'moduleName'=>$manifest['name'],
        'version'=>$manifest['version'],
        'oldVersion'=>$oldVersion,
        'user'=>$user,
        'moduleDir'=>__DIR__.'/private/modules/'.$manifest['slug'],
        'assetUrl'=>'index.php?page=module_asset&slug='.rawurlencode($manifest['slug']).'&asset=',
    ];
}

function moduleManager(PDO $pdo,string $globalPrefix,string $users,string $groups,string $registry,string $frontends,string $groupFrontends,array $user):never
{
    $moduleRoot=__DIR__.'/private/modules';
    if(!is_dir($moduleRoot))@mkdir($moduleRoot,0700,true);
    $error='';$flash=(string)($_SESSION['module_flash']??'');unset($_SESSION['module_flash']);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        csrf();$action=(string)($_POST['action']??'');
        try{
            if($action==='upload'){
                $upload=$_FILES['package']??null;
                if(!$upload||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Wähle ein ZIP-Modulpaket aus.');
                if(!is_uploaded_file((string)($upload['tmp_name']??'')))throw new RuntimeException('Das hochgeladene Paket ist ungültig.');
                if((int)$upload['size']>10*1024*1024)throw new RuntimeException('Das ZIP darf höchstens 10 MB groß sein.');
                $stage=$moduleRoot.'/.stage_'.bin2hex(random_bytes(8));
                $manifest=extractModulePackage((string)$upload['tmp_name'],$stage);
                $stmt=$pdo->prepare("SELECT id,slug,title,version,db_prefix FROM $registry WHERE slug=?");
                $stmt->execute([$manifest['slug']]);$existing=$stmt->fetch()?:null;
                $stmt=$pdo->prepare("SELECT id FROM $registry WHERE db_prefix=? AND slug<>?");
                $stmt->execute([$manifest['db_prefix'],$manifest['slug']]);
                if($stmt->fetch())throw new RuntimeException('Dieser kurze Datenbankpräfix wird bereits von einem anderen Modul verwendet.');
                $target=$moduleRoot.'/'.$manifest['slug'];
                if(!$existing){
                    $prefixStart=$globalPrefix.$manifest['db_prefix'].'_';
                    $collision=$pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,CHAR_LENGTH(?))=? LIMIT 1");
                    $collision->execute([$prefixStart,$prefixStart]);
                    if($collision->fetchColumn())throw new RuntimeException('Unter diesem Modulpräfix existieren bereits Tabellen. Wähle einen anderen db_prefix.');
                    if(is_dir($target))throw new RuntimeException('Moduldateien existieren bereits ohne Registry-Eintrag; bereinige den Modulordner vor der Installation.');
                    if(!rename($stage,$target))throw new RuntimeException('Moduldateien konnten nicht aktiviert werden.');
                    $ctx=moduleContext($pdo,$globalPrefix,$manifest,$user);
                    try{
                        runModuleHook($target,'install.php',$ctx);
                        $pdo->beginTransaction();
                        $stmt=$pdo->prepare("INSERT INTO $registry(slug,title,version,db_prefix) VALUES(?,?,?,?)");
                        $stmt->execute([$manifest['slug'],$manifest['name'],$manifest['version'],$manifest['db_prefix']]);
                        $moduleId=(int)$pdo->lastInsertId();
                        $insert=$pdo->prepare("INSERT INTO $frontends(module_id,frontend_id,name,file_name) VALUES(?,?,?,?)");
                        foreach($manifest['frontends'] as $frontend)$insert->execute([$moduleId,$frontend['id'],$frontend['name'],$frontend['file']]);
                        $default=(int)$manifest['frontends'][0]['id'];
                        $groupsList=$pdo->query("SELECT id FROM $groups")->fetchAll(PDO::FETCH_COLUMN);
                        $assign=$pdo->prepare("INSERT INTO $groupFrontends(group_id,module_id,frontend_id) VALUES(?,?,?)");
                        foreach($groupsList as $groupId)$assign->execute([(int)$groupId,$moduleId,$default]);
                        $pdo->commit();
                    }catch(Throwable $exception){
                        if($pdo->inTransaction())$pdo->rollBack();
                        try{runModuleHook($target,'delete.php',$ctx);}catch(Throwable $ignored){}
                        removeModuleTree($target);
                        throw $exception;
                    }
                    $_SESSION['module_flash']='Modul installiert. Das erste Frontend wurde allen Gruppen zugewiesen.';
                    go('index.php?page=modules');
                }
                if($manifest['db_prefix']!==$existing['db_prefix'])throw new RuntimeException('Beim Aktualisieren darf db_prefix nicht geändert werden.');
                if(version_compare($manifest['version'],$existing['version'],'<='))throw new RuntimeException('Die hochgeladene Modulversion muss neuer sein als die installierte Version.');
                if(!is_dir($target))throw new RuntimeException('Der installierte Modulordner fehlt.');
                $backup=$moduleRoot.'/.backup_'.$manifest['slug'].'_'.bin2hex(random_bytes(5));
                if(!rename($target,$backup))throw new RuntimeException('Die bisherige Modulversion konnte nicht gesichert werden.');
                if(!rename($stage,$target)){rename($backup,$target);throw new RuntimeException('Die neue Modulversion konnte nicht aktiviert werden.');}
                $ctx=moduleContext($pdo,$globalPrefix,$manifest,$user,(string)$existing['version']);
                try{
                    runModuleHook($target,'update.php',$ctx);
                    $moduleId=(int)$existing['id'];
                    $stmt=$pdo->prepare("SELECT group_id,frontend_id FROM $groupFrontends WHERE module_id=?");
                    $stmt->execute([$moduleId]);$previous=[];
                    foreach($stmt->fetchAll() as $row)$previous[(int)$row['group_id']]=(int)$row['frontend_id'];
                    $pdo->beginTransaction();
                    $stmt=$pdo->prepare("UPDATE $registry SET title=?,version=? WHERE id=?");
                    $stmt->execute([$manifest['name'],$manifest['version'],$moduleId]);
                    $stmt=$pdo->prepare("DELETE FROM $frontends WHERE module_id=?");$stmt->execute([$moduleId]);
                    $insert=$pdo->prepare("INSERT INTO $frontends(module_id,frontend_id,name,file_name) VALUES(?,?,?,?)");
                    foreach($manifest['frontends'] as $frontend)$insert->execute([$moduleId,$frontend['id'],$frontend['name'],$frontend['file']]);
                    $valid=array_column($manifest['frontends'],'id');$default=(int)$manifest['frontends'][0]['id'];
                    $assign=$pdo->prepare("INSERT INTO $groupFrontends(group_id,module_id,frontend_id) VALUES(?,?,?)");
                    foreach($pdo->query("SELECT id FROM $groups")->fetchAll(PDO::FETCH_COLUMN) as $groupId){$old=$previous[(int)$groupId]??$default;$assign->execute([(int)$groupId,$moduleId,in_array($old,$valid,true)?$old:$default]);}
                    $pdo->commit();
                    try{removeModuleTree($backup);}catch(Throwable $ignored){}
                }catch(Throwable $exception){
                    if($pdo->inTransaction())$pdo->rollBack();
                    removeModuleTree($target);rename($backup,$target);throw $exception;
                }
                $_SESSION['module_flash']='Modul wurde auf Version '.$manifest['version'].' aktualisiert.';
                go('index.php?page=modules');
            }elseif($action==='delete'){
                $moduleId=(int)($_POST['module_id']??0);
                $stmt=$pdo->prepare("SELECT id,slug,title,version,db_prefix FROM $registry WHERE id=?");$stmt->execute([$moduleId]);$module=$stmt->fetch();
                if(!$module)throw new RuntimeException('Modul nicht gefunden.');
                $stmt=$pdo->prepare("SELECT frontend_id,name,file_name FROM $frontends WHERE module_id=? ORDER BY frontend_id");$stmt->execute([$moduleId]);
                $manifest=['slug'=>$module['slug'],'name'=>$module['title'],'version'=>$module['version'],'db_prefix'=>$module['db_prefix'],'frontends'=>$stmt->fetchAll()];
                $manifest['frontends']=array_map(static fn($f)=>['id'=>(int)$f['frontend_id'],'name'=>$f['name'],'file'=>$f['file_name']],$manifest['frontends']);
                $target=$moduleRoot.'/'.$module['slug'];
                if(!is_dir($target))throw new RuntimeException('Der Modulordner fehlt; delete.php kann nicht ausgeführt werden.');
                runModuleHook($target,'delete.php',moduleContext($pdo,$globalPrefix,$manifest,$user));
                $stmt=$pdo->prepare("DELETE FROM $registry WHERE id=?");$stmt->execute([$moduleId]);
                removeModuleTree($target);
                $_SESSION['module_flash']='Modul deinstalliert. Die Datenbankbereinigung wurde an delete.php übergeben.';
                go('index.php?page=modules');
            }elseif($action==='save_frontends'){
                $moduleId=(int)($_POST['module_id']??0);$submitted=$_POST['frontend']??[];
                if(!is_array($submitted))$submitted=[];
                $stmt=$pdo->prepare("SELECT frontend_id FROM $frontends WHERE module_id=? ORDER BY frontend_id");$stmt->execute([$moduleId]);
                $valid=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
                if(!$valid)throw new RuntimeException('Modul-Frontends wurden nicht gefunden.');
                $stmt=$pdo->query("SELECT id FROM $groups ORDER BY id");$groupIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
                $stmt=$pdo->prepare("DELETE FROM $groupFrontends WHERE module_id=?");$stmt->execute([$moduleId]);
                $insert=$pdo->prepare("INSERT INTO $groupFrontends(group_id,module_id,frontend_id) VALUES(?,?,?)");
                foreach($groupIds as $groupId){$choice=(int)($submitted[$groupId]??$valid[0]);if(!in_array($choice,$valid,true))$choice=$valid[0];$insert->execute([$groupId,$moduleId,$choice]);}
                $_SESSION['module_flash']='Frontend-Auswahl je Benutzergruppe gespeichert.';
                go('index.php?page=modules');
            }else throw new RuntimeException('Unbekannte Modulaktion.');
        }catch(Throwable $exception){
            if(isset($stage)&&is_dir($stage))removeModuleTree($stage);
            $error=$exception instanceof JsonException?'module.json ist ungültiges JSON.':$exception->getMessage();
        }
    }

    $modules=$pdo->query("SELECT id,slug,title,version,db_prefix,installed_at FROM $registry ORDER BY title")->fetchAll();
    $groupsList=$pdo->query("SELECT id,name FROM $groups ORDER BY name")->fetchAll();
    $body='<section class="panel"><div class="eyebrow">ADMINISTRATION</div><h1>Modulmanager</h1><p class="lead">Installiere vertrauenswürdige ZIP-Module, aktualisiere sie durch erneutes Hochladen und verwalte das Frontend je Benutzergruppe.</p><div class="notice warn"><b>Hinweis:</b> PHP-Module führen Servercode mit den Rechten dieser Anwendung aus. Installiere nur Pakete aus vertrauenswürdiger Quelle.</div>'.($flash?'<div class="notice success">'.e($flash).'</div>':'').($error?'<div class="notice error">'.e($error).'</div>':'');
    $body.='<section class="subpanel module-upload"><h2>Modul installieren oder aktualisieren</h2><form method="post" enctype="multipart/form-data" action="index.php?page=modules"><input type="hidden" name="csrf" value="'.e(tok()).'"><input type="hidden" name="action" value="upload"><label>Modulpaket (ZIP)<input type="file" name="package" accept=".zip,application/zip" required></label><small>Installiert eine neue Modul-ID oder aktualisiert ein Modul mit demselben slug.</small><button class="button">Paket prüfen und installieren</button></form></section>';
    if(!$modules)$body.='<section class="subpanel"><p class="muted">Noch keine Module installiert.</p></section>';
    foreach($modules as $module){
        $id=(int)$module['id'];
        $stmt=$pdo->prepare("SELECT frontend_id,name,file_name FROM $frontends WHERE module_id=? ORDER BY frontend_id");$stmt->execute([$id]);$fronts=$stmt->fetchAll();
        $stmt=$pdo->prepare("SELECT group_id,frontend_id FROM $groupFrontends WHERE module_id=?");$stmt->execute([$id]);$selected=[];foreach($stmt->fetchAll() as $row)$selected[(int)$row['group_id']]=(int)$row['frontend_id'];
        $default=(int)($fronts[0]['frontend_id']??0);
        $body.='<section class="subpanel installed-module"><div class="module-heading"><div><div class="eyebrow">INSTALLIERT · VERSION '.e($module['version']).'</div><h2>'.e($module['title']).'</h2><p class="muted">slug: '.e($module['slug']).' · Tabellenpräfix: '.e($globalPrefix.$module['db_prefix'].'_').'</p></div><form method="post" action="index.php?page=modules"><input type="hidden" name="csrf" value="'.e(tok()).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="module_id" value="'.$id.'"><button class="link-danger">Deinstallieren</button><small>delete.php wird ausgeführt</small></form></div>';
        if($fronts){
            $body.='<h3>Frontend für Benutzergruppen</h3><form method="post" action="index.php?page=modules"><input type="hidden" name="csrf" value="'.e(tok()).'"><input type="hidden" name="action" value="save_frontends"><input type="hidden" name="module_id" value="'.$id.'">';
            if(!$groupsList)$body.='<p class="muted">Es gibt noch keine Benutzergruppen. Nutzer ohne Gruppen sehen das erste Frontend.</p>';
            foreach($groupsList as $group){
                $value=$selected[(int)$group['id']]??$default;
                $body.='<label class="front-choice">'.e($group['name']).'<select name="frontend['.(int)$group['id'].']">';
                foreach($fronts as $frontend)$body.='<option value="'.(int)$frontend['frontend_id'].'"'.((int)$frontend['frontend_id']===$value?' selected':'').'>'.(int)$frontend['frontend_id'].' · '.e($frontend['name']).'</option>';
                $body.='</select></label>';
            }
            $body.='<button class="button">Frontend-Auswahl speichern</button></form><div class="frontend-list"><strong>Frontends:</strong> ';
            foreach($fronts as $index=>$frontend)$body.=($index?', ':'').(int)$frontend['frontend_id'].' · '.e($frontend['name']);
            $body.='</div>';
        }
        $body.='</section>';
    }
    $body.='<section class="subpanel module-contract"><h2>Modulvertrag</h2><p>Ein ZIP enthält module.json, install.php, update.php, delete.php und mindestens eine Datei frontend_ID_name.php. Dateien liegen im privaten Modulordner. Die Hooks erhalten $moduleContext mit PDO, Benutzer und Tabellenpräfix. Tabellenpräfix: globaler Präfix + db_prefix + Unterstrich.</p><p>Wenn ein Benutzer mehreren Gruppen angehört, gilt die Auswahl seiner Gruppe mit der kleinsten Gruppen-ID. Ohne Gruppenzuordnung wird das erste Frontend verwendet. Beim Installieren wird das erste Frontend für alle vorhandenen Gruppen voreingestellt.</p></section></section>';
    view('Modulmanager',$body,true);
}

function runModuleFrontend(PDO $pdo,string $globalPrefix,string $users,string $members,string $groups,string $registry,string $frontends,string $groupFrontends,array $user):never
{
    $slug=(string)($_GET['slug']??'');
    if(!preg_match('/^[a-z][a-z0-9_-]{1,49}$/',$slug)){http_response_code(404);view('Modul nicht gefunden','<section class="panel"><h1>Modul nicht gefunden</h1></section>',true);}
    $stmt=$pdo->prepare("SELECT id,slug,title,version,db_prefix FROM $registry WHERE slug=?");$stmt->execute([$slug]);$module=$stmt->fetch();
    if(!$module){http_response_code(404);view('Modul nicht gefunden','<section class="panel"><h1>Modul nicht gefunden</h1></section>',true);}
    $stmt=$pdo->prepare("SELECT frontend_id,name,file_name FROM $frontends WHERE module_id=? ORDER BY frontend_id");$stmt->execute([$module['id']]);$available=$stmt->fetchAll();
    if(!$available){http_response_code(404);view('Frontend nicht gefunden','<section class="panel"><h1>Frontend nicht gefunden</h1></section>',true);}
    $stmt=$pdo->prepare("SELECT group_id FROM $members WHERE user_id=? ORDER BY group_id");$stmt->execute([$user['id']]);$groupIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    $chosen=$available[0];
    foreach($groupIds as $groupId){$pick=$pdo->prepare("SELECT f.frontend_id,f.name,f.file_name FROM $groupFrontends gf JOIN $frontends f ON f.module_id=gf.module_id AND f.frontend_id=gf.frontend_id WHERE gf.group_id=? AND gf.module_id=?");$pick->execute([$groupId,$module['id']]);$row=$pick->fetch();if($row){$chosen=$row;break;}}
    $directory=__DIR__.'/private/modules/'.$module['slug'];
    $file=$directory.'/'.$chosen['file_name'];
    if(!is_file($file)){http_response_code(500);view('Moduldatei fehlt','<section class="panel"><h1>Frontend-Datei fehlt</h1></section>',true);}
    $ctx=moduleContext($pdo,$globalPrefix,['slug'=>$module['slug'],'name'=>$module['title'],'version'=>$module['version'],'db_prefix'=>$module['db_prefix']],$user);
    $ctx->frontendId=(int)$chosen['frontend_id'];$ctx->frontendName=$chosen['name'];$ctx->moduleDir=$directory;$ctx->assetUrl='index.php?page=module_asset&slug='.rawurlencode($module['slug']).'&asset=';$ctx->csrfToken=tok();
    $render=static function(string $__path,object $moduleContext):string{ob_start();try{include $__path;return (string)ob_get_clean();}catch(Throwable $exception){ob_end_clean();throw $exception;}};
    try{$body='<section class="panel"><div class="eyebrow">'.e($module['title']).' · '.e($chosen['name']).'</div>'.$render($file,$ctx).'</section>';}
    catch(Throwable $exception){http_response_code(500);$body='<section class="panel"><h1>Modul konnte nicht geladen werden</h1><p>Das Modulfrontend hat einen Fehler ausgelöst.</p></section>';}
    $GLOBALS['currentPage']='module';
    view($module['title'],$body,true);
}

function serveModuleAsset(string $slug):never
{
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
    $asset=(string)($_GET['asset']??'');
    if(strlen($asset)>240||!preg_match('/^[A-Za-z0-9_.-]+(?:\\/[A-Za-z0-9_.-]+)*$/',$asset)){http_response_code(404);exit;}
    foreach(explode('/',$asset) as $part)if($part===''||$part==='.'||$part==='..'||str_starts_with($part,'.')){http_response_code(404);exit;}
    $types=['css'=>'text/css; charset=utf-8','js'=>'application/javascript; charset=utf-8','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','woff'=>'font/woff','woff2'=>'font/woff2'];
    $extension=strtolower(pathinfo($asset,PATHINFO_EXTENSION));
    if(!isset($types[$extension])){http_response_code(404);exit;}
    $base=realpath(__DIR__.'/private/modules/'.$slug);
    $path=realpath(__DIR__.'/private/modules/'.$slug.'/'.$asset);
    if(!$base||!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)||!is_file($path)){http_response_code(404);exit;}
    header('Content-Type: '.$types[$extension]);header('Cache-Control: private, max-age=300');header('Content-Length: '.(string)filesize($path));
    readfile($path);exit;
}

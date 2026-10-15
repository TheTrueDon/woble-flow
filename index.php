<?php
declare(strict_types=1);
const CFG=__DIR__.'/private/config.php';
function tls():bool{return (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';}
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function go(string $u):never{header('Location: '.$u,true,303);exit;}
function tok():string{return $_SESSION['csrf']??=bin2hex(random_bytes(32));}
function csrf():void{if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))){http_response_code(400);exit('Ungültige Anfrage. Bitte neu laden.');}}
function db(array $c):PDO{return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$c['db_host'],$c['db_port'],$c['db_name']),$c['db_user'],$c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
function view(string $title,string $body,bool $auth=false):never{$secure=tls();$current=(string)($GLOBALS['currentPage']??'');$display=(string)($GLOBALS['currentDisplayName']??'');$modules=$GLOBALS['moduleMenu']??[];?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> · Basis</title><link rel="stylesheet" href="assets/style.css"></head><body><?php if($auth):?><div class="app-shell"><aside class="sidebar"><a class="brand" href="index.php?page=profile">Basis<span>.</span></a><div class="nav-caption">ARBEITSBEREICH</div><nav class="side-nav"><a class="<?=$current==='profile'?'active':''?>" href="index.php?page=profile">Übersicht</a><?php foreach($modules as $item):?><a class="module-link <?=$current==='module'&&($_GET['slug']??'')===$item['slug']?'active':''?>" href="index.php?page=module&amp;slug=<?=e($item['slug'])?>"><?=e($item['title'])?></a><?php endforeach?></nav><?php if(!empty($_SESSION['is_admin'])):?><div class="nav-caption">ADMINISTRATION</div><nav class="side-nav"><a class="<?=$current==='admin'?'active':''?>" href="index.php?page=admin">Benutzer &amp; Gruppen</a><a class="<?=$current==='modules'?'active':''?>" href="index.php?page=modules">Modulmanager</a></nav><?php endif?><div class="sidebar-bottom"><span><?=e($display)?></span><form method="post" action="index.php?page=logout"><input type="hidden" name="csrf" value="<?=e(tok())?>"><button class="navlink">Abmelden</button></form></div></aside><div class="workspace"><header class="workspace-header"><span><?=e($title)?></span><span class="user-chip"><?=e($display)?></span></header><?php else:?><div class="guest-shell"><?php endif?><main class="container"><?php if(!$secure):?><div class="notice warn"><b>HTTP-Testbetrieb:</b> Die Verbindung ist unverschlüsselt. Verwende HTTPS für echte Konten.</div><?php endif?><?=$body?></main><footer>Login-Grundgerüst · <?=$secure?'HTTPS aktiv':'HTTP-Testbetrieb'?></footer></div><?php if($auth):?></div><?php endif?></body></html><?php exit;}
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: strict-origin-when-cross-origin');header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");if(tls())header('Strict-Transport-Security: max-age=31536000');
session_name('basicapp_session');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>tls(),'httponly'=>true,'samesite'=>'Lax']);session_start();
$page=(string)($_GET['page']??'');
if(!is_file(CFG)){
 $err='';
 if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf();$h=trim((string)($_POST['db_host']??''));$port=(int)($_POST['db_port']??3306);$n=trim((string)($_POST['db_name']??''));$du=trim((string)($_POST['db_user']??''));$dp=(string)($_POST['db_password']??'');$pre=trim((string)($_POST['table_prefix']??'app_'));$un=trim((string)($_POST['admin_username']??''));$dn=trim((string)($_POST['display_name']??''));$pw=(string)($_POST['admin_password']??'');$pw2=(string)($_POST['admin_password_confirm']??'');
  if($h===''||$n===''||$du===''||$port<1||$port>65535)$err='Bitte prüfe die Datenbankangaben.';
  elseif(!preg_match('/^[A-Za-z0-9_]{1,32}$/',$pre))$err='Präfix darf nur Buchstaben, Zahlen und Unterstriche enthalten (max. 32 Zeichen).';
  elseif(!preg_match('/^[A-Za-z0-9._-]{3,64}$/',$un))$err='Benutzername: 3–64 Zeichen, Buchstaben, Zahlen, Punkt, Unterstrich oder Bindestrich.';
  elseif($dn===''||mb_strlen($dn)>100)$err='Anzeigename muss 1–100 Zeichen lang sein.';
  elseif(strlen($pw)<12)$err='Passwort muss mindestens 12 Zeichen lang sein.';
  elseif(!hash_equals($pw,$pw2))$err='Passwörter stimmen nicht überein.';
  else try{
   $c=['db_host'=>$h,'db_port'=>$port,'db_name'=>$n,'db_user'=>$du,'db_password'=>$dp,'table_prefix'=>$pre,'app_secret'=>bin2hex(random_bytes(32))];$pdo=db($c);$ut='`'.$pre.'users`';$lt='`'.$pre.'login_attempts`';
   $pdo->exec("CREATE TABLE IF NOT EXISTS $ut (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) NOT NULL UNIQUE, display_name VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, is_admin TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, last_login_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
   $pdo->exec("CREATE TABLE IF NOT EXISTS $lt (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip_hash CHAR(64) NOT NULL, attempted_at DATETIME NOT NULL, INDEX idx_ip_time (ip_hash,attempted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
   $q=$pdo->prepare("INSERT INTO $ut(username,display_name,password_hash) VALUES(?,?,?)");$q->execute([$un,$dn,password_hash($pw,PASSWORD_DEFAULT)]);
   if(!is_dir(__DIR__.'/private')&&!mkdir(__DIR__.'/private',0700,true))throw new RuntimeException('Ordner private konnte nicht erstellt werden.');
   $tmp=CFG.'.tmp.'.bin2hex(random_bytes(5));$bytes=file_put_contents($tmp,"<?php
declare(strict_types=1);
return ".var_export($c,true).";
",LOCK_EX);
   if($bytes===false||!rename($tmp,CFG))throw new RuntimeException('Konfiguration nicht gespeichert. Schreibrechte für private prüfen.');@chmod(CFG,0600);
   session_regenerate_id(true);$_SESSION['user_id']=(int)$pdo->lastInsertId();$_SESSION['csrf']=bin2hex(random_bytes(32));go('index.php?page=profile');
  }catch(Throwable $x){$err='Installation fehlgeschlagen. Prüfe Zugangsdaten und Schreibrechte. '.$x->getMessage();}
 }
 $body='<section class="panel narrow"><div class="eyebrow">ERSTEINRICHTUNG</div><h1>Anwendung installieren</h1><p class="lead">Datenbank verbinden und Administratorkonto anlegen.</p>'.($err?'<div class="notice error">'.e($err).'</div>':'').'<form method="post" action="index.php"><input type="hidden" name="csrf" value="'.e(tok()).'"><h2>Datenbank</h2><div class="grid"><label>DB-Server<input name="db_host" value="'.e((string)($_POST['db_host']??'localhost')).'" required></label><label>Port<input name="db_port" type="number" value="'.e((string)($_POST['db_port']??'3306')).'" required></label></div><label>Datenbankname<input name="db_name" required value="'.e((string)($_POST['db_name']??'')).'"></label><div class="grid"><label>DB-Benutzer<input name="db_user" required value="'.e((string)($_POST['db_user']??'')).'"></label><label>DB-Passwort<input name="db_password" type="password"></label></div><label>Tabellenpräfix<input name="table_prefix" value="'.e((string)($_POST['table_prefix']??'app_')).'" required><small>Buchstaben, Zahlen und Unterstriche.</small></label><hr><h2>Administratorkonto</h2><div class="grid"><label>Benutzername<input name="admin_username" required value="'.e((string)($_POST['admin_username']??'')).'"></label><label>Anzeigename<input name="display_name" required value="'.e((string)($_POST['display_name']??'')).'"></label></div><label>Passwort, mindestens 12 Zeichen<input name="admin_password" type="password" minlength="12" required></label><label>Passwort wiederholen<input name="admin_password_confirm" type="password" minlength="12" required></label><button class="button">Installation starten</button></form><p class="smallprint">HTTP ist für Tests möglich. Dabei werden Zugangsdaten unverschlüsselt übertragen. Produktivbetrieb über HTTPS.</p></section>';
 view('Installation',$body);
}
$c=require CFG;try{$pdo=db($c);}catch(Throwable $x){http_response_code(500);exit('Datenbankverbindung fehlgeschlagen. Konfiguration oder DB prüfen.');}
$pre=$c['table_prefix'];$ut=chr(96).$pre.'users'.chr(96);$lt=chr(96).$pre.'login_attempts'.chr(96);$gt=chr(96).$pre.'groups'.chr(96);$mt=chr(96).$pre.'group_memberships'.chr(96);$rt=chr(96).$pre.'modules'.chr(96);$ft=chr(96).$pre.'module_frontends'.chr(96);$gft=chr(96).$pre.'module_group_frontends'.chr(96);$user=null;
try {
 $column=$pdo->query("SHOW COLUMNS FROM $ut LIKE 'is_admin'")->fetch();
 if(!$column)$pdo->exec("ALTER TABLE $ut ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
 $exists=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
 $exists->execute([$pre.'groups']);
 if(!$exists->fetchColumn())$pdo->exec("CREATE TABLE $gt (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL UNIQUE,description VARCHAR(255) NOT NULL DEFAULT '',created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $exists->execute([$pre.'group_memberships']);
 if(!$exists->fetchColumn())$pdo->exec("CREATE TABLE $mt (user_id BIGINT UNSIGNED NOT NULL,group_id BIGINT UNSIGNED NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,group_id),INDEX idx_group_id(group_id),FOREIGN KEY(user_id) REFERENCES $ut(id) ON DELETE CASCADE,FOREIGN KEY(group_id) REFERENCES $gt(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $exists->execute([$pre.'modules']);
 if(!$exists->fetchColumn())$pdo->exec("CREATE TABLE $rt (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(50) NOT NULL UNIQUE,title VARCHAR(120) NOT NULL,version VARCHAR(32) NOT NULL,db_prefix VARCHAR(3) NOT NULL UNIQUE,installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $exists->execute([$pre.'module_frontends']);
 if(!$exists->fetchColumn())$pdo->exec("CREATE TABLE $ft (module_id BIGINT UNSIGNED NOT NULL,frontend_id SMALLINT UNSIGNED NOT NULL,name VARCHAR(100) NOT NULL,file_name VARCHAR(120) NOT NULL,PRIMARY KEY(module_id,frontend_id),FOREIGN KEY(module_id) REFERENCES $rt(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $exists->execute([$pre.'module_group_frontends']);
 if(!$exists->fetchColumn())$pdo->exec("CREATE TABLE $gft (group_id BIGINT UNSIGNED NOT NULL,module_id BIGINT UNSIGNED NOT NULL,frontend_id SMALLINT UNSIGNED NOT NULL,PRIMARY KEY(group_id,module_id),INDEX idx_group_module(module_id),FOREIGN KEY(group_id) REFERENCES $gt(id) ON DELETE CASCADE,FOREIGN KEY(module_id,frontend_id) REFERENCES $ft(module_id,frontend_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $q=$pdo->query("SELECT COUNT(*) FROM $ut WHERE is_admin=1");
 if((int)$q->fetchColumn()===0){$first=$pdo->query("SELECT id FROM $ut ORDER BY id ASC LIMIT 1")->fetchColumn();if($first!==false){$q=$pdo->prepare("UPDATE $ut SET is_admin=1 WHERE id=?");$q->execute([(int)$first]);}}
} catch(Throwable $x) {http_response_code(500);exit('Datenbankaktualisierung fehlgeschlagen. Das Datenbankkonto benötigt Rechte für ALTER und CREATE TABLE.');}
if(isset($_SESSION['user_id'])){$q=$pdo->prepare("SELECT id,username,display_name,is_admin FROM $ut WHERE id=?");$q->execute([(int)$_SESSION['user_id']]);$user=$q->fetch()?:null;if(!$user){$_SESSION=[];session_regenerate_id(true);}}
if($user){$_SESSION['is_admin']=(bool)$user['is_admin'];$_SESSION['display_name']=$user['display_name'];$GLOBALS['currentDisplayName']=$user['display_name'];}else{unset($_SESSION['is_admin'],$_SESSION['display_name']);}
require_once __DIR__.'/module_manager.php';
$GLOBALS['currentPage']=$page;
$GLOBALS['moduleMenu']=$user?moduleNavigation($pdo,$pre,(int)$user['id'],$ut,$mt,$gt,$rt,$ft,$gft):[];
if($page==='modules'){
 if(!$user){go('index.php?page=login');}
 if(!(bool)$user['is_admin']){http_response_code(403);view('Kein Zugriff','<section class="panel"><h1>Kein Zugriff</h1><p>Der Modulmanager ist nur für Administratoren verfügbar.</p></section>',true);}
 moduleManager($pdo,$pre,$ut,$gt,$rt,$ft,$gft,$user);
}
if($page==='module_asset'){
 if(!$user){go('index.php?page=login');}
 $assetSlug=(string)($_GET['slug']??'');if(!preg_match('/^[a-z][a-z0-9_-]{1,49}$/',$assetSlug)){http_response_code(404);exit;}serveModuleAsset($assetSlug);
}
if($page==='module'){
 if(!$user){go('index.php?page=login');}
 runModuleFrontend($pdo,$pre,$ut,$mt,$gt,$rt,$ft,$gft,$user);
}

if($page==='admin'){
 if(!$user){go('index.php?page=login');}
 if(!(bool)$user['is_admin']){http_response_code(403);view('Kein Zugriff','<section class="panel"><h1>Kein Zugriff</h1><p>Die Verwaltung ist nur für Administratoren verfügbar.</p></section>',true);}
 require __DIR__.'/admin.php';
 adminPage($pdo,$ut,$gt,$mt,$rt,$ft,$gft,$user);
}
if($page==='logout'&&$_SERVER['REQUEST_METHOD']==='POST'){csrf();$_SESSION=[];session_regenerate_id(true);go('index.php');}
if(!$user)$page='login';elseif($page==='login')$page='profile';
if($page==='login'){
 $err='';
 if($_SERVER['REQUEST_METHOD']==='POST'){csrf();$un=trim((string)($_POST['username']??''));$pw=(string)($_POST['password']??'');$ip=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'),$c['app_secret']);$pdo->exec("DELETE FROM $lt WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");$q=$pdo->prepare("SELECT COUNT(*) FROM $lt WHERE ip_hash=? AND attempted_at>(NOW() - INTERVAL 15 MINUTE)");$q->execute([$ip]);$a=null;if((int)$q->fetchColumn()<10){$q=$pdo->prepare("SELECT id,password_hash FROM $ut WHERE username=? LIMIT 1");$q->execute([$un]);$a=$q->fetch()?:null;}
  if($a&&password_verify($pw,$a['password_hash'])){$q=$pdo->prepare("DELETE FROM $lt WHERE ip_hash=?");$q->execute([$ip]);if(password_needs_rehash($a['password_hash'],PASSWORD_DEFAULT)){$q=$pdo->prepare("UPDATE $ut SET password_hash=? WHERE id=?");$q->execute([password_hash($pw,PASSWORD_DEFAULT),$a['id']]);}$q=$pdo->prepare("UPDATE $ut SET last_login_at=NOW() WHERE id=?");$q->execute([$a['id']]);session_regenerate_id(true);$_SESSION['user_id']=(int)$a['id'];$_SESSION['csrf']=bin2hex(random_bytes(32));go('index.php?page=profile');}
  $q=$pdo->prepare("INSERT INTO $lt(ip_hash,attempted_at) VALUES(?,NOW())");$q->execute([$ip]);$err='Anmeldung fehlgeschlagen. Zugangsdaten prüfen oder 15 Minuten warten.';
 }
 view('Anmelden','<section class="panel narrow"><div class="eyebrow">WILLKOMMEN ZURÜCK</div><h1>Anmelden</h1><p class="lead">Melde dich mit deinem Benutzerkonto an.</p>'.($err?'<div class="notice error">'.e($err).'</div>':'').'<form method="post" action="index.php?page=login"><input type="hidden" name="csrf" value="'.e(tok()).'"><label>Benutzername<input name="username" required autocomplete="username"></label><label>Passwort<input name="password" type="password" required autocomplete="current-password"></label><button class="button">Anmelden</button></form></section>');
}
if($page==='profile'){
 $err='';$ok='';
 if($_SERVER['REQUEST_METHOD']==='POST'){csrf();$nu=trim((string)($_POST['username']??''));$dn=trim((string)($_POST['display_name']??''));$cp=(string)($_POST['current_password']??'');$np=(string)($_POST['new_password']??'');$np2=(string)($_POST['new_password_confirm']??'');$q=$pdo->prepare("SELECT password_hash FROM $ut WHERE id=?");$q->execute([$user['id']]);$hash=(string)$q->fetchColumn();
  if(!preg_match('/^[A-Za-z0-9._-]{3,64}$/',$nu))$err='Benutzername ungültig: 3–64 Zeichen; Buchstaben, Zahlen, Punkt, _ oder -.';
  elseif($dn===''||mb_strlen($dn)>100)$err='Anzeigename muss 1–100 Zeichen lang sein.';
  elseif(!password_verify($cp,$hash))$err='Aktuelles Passwort ist nicht korrekt.';
  elseif($np!==''&&strlen($np)<12)$err='Neues Passwort muss mindestens 12 Zeichen haben.';
  elseif($np!==''&&!hash_equals($np,$np2))$err='Neue Passwörter stimmen nicht überein.';
  else try{if($np!==''){$q=$pdo->prepare("UPDATE $ut SET username=?,display_name=?,password_hash=? WHERE id=?");$q->execute([$nu,$dn,password_hash($np,PASSWORD_DEFAULT),$user['id']]);}else{$q=$pdo->prepare("UPDATE $ut SET username=?,display_name=? WHERE id=?");$q->execute([$nu,$dn,$user['id']]);}$ok='Kontodaten gespeichert.';$q=$pdo->prepare("SELECT id,username,display_name,is_admin FROM $ut WHERE id=?");$q->execute([$user['id']]);$user=$q->fetch();}catch(PDOException $x){$err='Benutzername ist bereits vergeben.';}
 }
 $body='<section class="panel"><div class="eyebrow">KONTO</div><h1>Deine Benutzerdaten</h1><p class="lead">Ändere hier Benutzername, Anzeigename und Passwort.</p>'.($ok?'<div class="notice success">'.e($ok).'</div>':'').($err?'<div class="notice error">'.e($err).'</div>':'').'<form method="post" action="index.php?page=profile"><input type="hidden" name="csrf" value="'.e(tok()).'"><div class="grid"><label>Benutzername<input name="username" value="'.e($user['username']).'" required></label><label>Anzeigename<input name="display_name" value="'.e($user['display_name']).'" required></label></div><hr><h2>Passwort ändern</h2><p class="muted">Leer lassen, wenn es unverändert bleiben soll.</p><div class="grid"><label>Neues Passwort<input name="new_password" type="password" minlength="12" autocomplete="new-password"></label><label>Wiederholen<input name="new_password_confirm" type="password" minlength="12" autocomplete="new-password"></label></div><label>Aktuelles Passwort zur Bestätigung<input name="current_password" type="password" required autocomplete="current-password"></label><button class="button">Änderungen speichern</button></form></section>';
 view('Mein Konto',$body,true);
}
go('index.php');

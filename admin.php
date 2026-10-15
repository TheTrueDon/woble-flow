<?php
declare(strict_types=1);

function adminPage(PDO $pdo, string $users, string $groupsTable, string $members, string $moduleRegistry, string $moduleFrontends, string $groupFrontends, string $dashboardPermissions, array $admin): never
{
    $tab = (($_GET['tab'] ?? 'users') === 'groups') ? 'groups' : 'users';
    $error = '';
    $flash = (string)($_SESSION['admin_flash'] ?? '');
    unset($_SESSION['admin_flash']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf();
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'save_group') {
                $id = (int)($_POST['group_id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                if ($name === '' || mb_strlen($name) > 100) {
                    $error = 'Der Gruppenname muss zwischen 1 und 100 Zeichen lang sein.';
                } elseif (mb_strlen($description) > 255) {
                    $error = 'Die Beschreibung darf höchstens 255 Zeichen lang sein.';
                } else {
                    if ($id > 0) {
                        $stmt = $pdo->prepare("UPDATE $groupsTable SET name=?,description=? WHERE id=?");
                        $stmt->execute([$name, $description, $id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO $groupsTable(name,description) VALUES(?,?)");
                        $stmt->execute([$name, $description]);
                        $newGroupId = (int)$pdo->lastInsertId();
                        $modules = $pdo->query("SELECT id FROM $moduleRegistry")->fetchAll(PDO::FETCH_COLUMN);
                        $front = $pdo->prepare("SELECT frontend_id FROM $moduleFrontends WHERE module_id=? ORDER BY frontend_id LIMIT 1");
                        $assign = $pdo->prepare("INSERT INTO $groupFrontends(group_id,module_id,frontend_id) VALUES(?,?,?)");
                        foreach ($modules as $moduleId) {
                            $front->execute([(int)$moduleId]);
                            $firstFrontend = $front->fetchColumn();
                            if ($firstFrontend !== false) $assign->execute([$newGroupId,(int)$moduleId,(int)$firstFrontend]);
                        }
                        $dashboards=$pdo->query("SELECT id,slug FROM $moduleRegistry")->fetchAll();
                        $permission=$pdo->prepare("INSERT IGNORE INTO $dashboardPermissions(group_id,module_id,enabled) VALUES(?,?,0)");
                        foreach($dashboards as $module){if(is_file(__DIR__.'/private/modules/'.$module['slug'].'/dashboard.php'))$permission->execute([$newGroupId,(int)$module['id']]);}
                    }
                    $_SESSION['admin_flash'] = 'Gruppe gespeichert.';
                    go('index.php?page=admin&tab=groups');
                }
            } elseif ($action === 'delete_group') {
                $stmt = $pdo->prepare("DELETE FROM $groupsTable WHERE id=?");
                $stmt->execute([(int)($_POST['group_id'] ?? 0)]);
                $_SESSION['admin_flash'] = 'Gruppe gelöscht. Zugehörige Zuordnungen wurden ebenfalls entfernt.';
                go('index.php?page=admin&tab=groups');
            } elseif ($action === 'save_user') {
                $id = (int)($_POST['user_id'] ?? 0);
                $username = trim((string)($_POST['username'] ?? ''));
                $display = trim((string)($_POST['display_name'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
                $groupIds = $_POST['groups'] ?? [];
                if (!is_array($groupIds)) $groupIds = [];
                $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), fn($v) => $v > 0)));
                $target = null;
                if ($id > 0) {
                    $stmt = $pdo->prepare("SELECT id,is_admin FROM $users WHERE id=?");
                    $stmt->execute([$id]);
                    $target = $stmt->fetch() ?: null;
                }
                if ($id > 0 && !$target) {
                    $error = 'Der Benutzer wurde nicht gefunden.';
                } elseif (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
                    $error = 'Benutzername: 3–64 Zeichen mit Buchstaben, Zahlen, Punkt, Unterstrich oder Bindestrich.';
                } elseif ($display === '' || mb_strlen($display) > 100) {
                    $error = 'Der Anzeigename muss zwischen 1 und 100 Zeichen lang sein.';
                } elseif ($id === 0 && strlen($password) < 12) {
                    $error = 'Ein neues Benutzerpasswort muss mindestens 12 Zeichen lang sein.';
                } elseif ($password !== '' && strlen($password) < 12) {
                    $error = 'Ein neues Passwort muss mindestens 12 Zeichen lang sein.';
                } elseif ($target && (int)$target['is_admin'] === 1 && !$isAdmin && (int)$target['id'] === (int)$admin['id']) {
                    $error = 'Du kannst deinen eigenen Administrationszugriff hier nicht entfernen.';
                } elseif ($target && (int)$target['is_admin'] === 1 && !$isAdmin && (int)$pdo->query("SELECT COUNT(*) FROM $users WHERE is_admin=1")->fetchColumn() <= 1) {
                    $error = 'Der letzte Administrator kann nicht herabgestuft werden.';
                } else {
                    $stmt = $pdo->query("SELECT id FROM $groupsTable");
                    $valid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                    $groupIds = array_values(array_intersect($groupIds, $valid));
                    try {
                        $pdo->beginTransaction();
                        if ($id > 0) {
                            if ($password !== '') {
                                $stmt = $pdo->prepare("UPDATE $users SET username=?,display_name=?,is_admin=?,password_hash=? WHERE id=?");
                                $stmt->execute([$username, $display, $isAdmin, password_hash($password, PASSWORD_DEFAULT), $id]);
                            } else {
                                $stmt = $pdo->prepare("UPDATE $users SET username=?,display_name=?,is_admin=? WHERE id=?");
                                $stmt->execute([$username, $display, $isAdmin, $id]);
                            }
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO $users(username,display_name,password_hash,is_admin) VALUES(?,?,?,?)");
                            $stmt->execute([$username, $display, password_hash($password, PASSWORD_DEFAULT), $isAdmin]);
                            $id = (int)$pdo->lastInsertId();
                        }
                        $stmt = $pdo->prepare("DELETE FROM $members WHERE user_id=?");
                        $stmt->execute([$id]);
                        $stmt = $pdo->prepare("INSERT INTO $members(user_id,group_id) VALUES(?,?)");
                        foreach ($groupIds as $groupId) $stmt->execute([$id, $groupId]);
                        $pdo->commit();
                        $_SESSION['admin_flash'] = 'Benutzer gespeichert.';
                        go('index.php?page=admin&tab=users');
                    } catch (PDOException $exception) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $error = 'Benutzer konnte nicht gespeichert werden. Der Benutzername ist möglicherweise bereits vergeben.';
                    }
                }
            } elseif ($action === 'delete_user') {
                $id = (int)($_POST['user_id'] ?? 0);
                if ($id === (int)$admin['id']) {
                    $error = 'Du kannst dein eigenes Konto nicht löschen.';
                } else {
                    $stmt = $pdo->prepare("SELECT is_admin FROM $users WHERE id=?");
                    $stmt->execute([$id]);
                    $targetAdmin = (int)($stmt->fetchColumn() ?: 0);
                    if ($targetAdmin === 1 && (int)$pdo->query("SELECT COUNT(*) FROM $users WHERE is_admin=1")->fetchColumn() <= 1) {
                        $error = 'Der letzte Administrator kann nicht gelöscht werden.';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM $users WHERE id=?");
                        $stmt->execute([$id]);
                        $_SESSION['admin_flash'] = 'Benutzer gelöscht.';
                        go('index.php?page=admin&tab=users');
                    }
                }
            } else {
                $error = 'Unbekannte Aktion.';
            }
        } catch (PDOException $exception) {
            $error = 'Die Änderung konnte nicht gespeichert werden. Prüfe, ob der Name bereits vergeben ist.';
        }
    }

    $groups = $pdo->query("SELECT id,name,description FROM $groupsTable ORDER BY name")->fetchAll();
    $groupEdit = null;
    if (isset($_GET['edit_group'])) {
        $stmt = $pdo->prepare("SELECT id,name,description FROM $groupsTable WHERE id=?");
        $stmt->execute([(int)$_GET['edit_group']]);
        $groupEdit = $stmt->fetch() ?: null;
    }

    $userEdit = null;
    $assigned = [];
    if (isset($_GET['edit_user'])) {
        $stmt = $pdo->prepare("SELECT id,username,display_name,is_admin FROM $users WHERE id=?");
        $stmt->execute([(int)$_GET['edit_user']]);
        $userEdit = $stmt->fetch() ?: null;
        if ($userEdit) {
            $stmt = $pdo->prepare("SELECT group_id FROM $members WHERE user_id=?");
            $stmt->execute([$userEdit['id']]);
            $assigned = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
    }

    $body = '<section class="panel admin-panel"><div class="eyebrow">ADMINISTRATION</div><h1>Verwaltung</h1><p class="lead">Verwalte Konten und Gruppen. Gruppenmitgliedschaften sind Mehrfachzuordnungen.</p>'
        . ($flash ? '<div class="notice success">'.e($flash).'</div>' : '')
        . ($error ? '<div class="notice error">'.e($error).'</div>' : '')
        . '<div class="tabs"><a class="'.($tab==='users'?'active':'').'" href="index.php?page=admin&tab=users">Benutzer</a><a class="'.($tab==='groups'?'active':'').'" href="index.php?page=admin&tab=groups">Benutzergruppen</a></div>';

    if ($tab === 'groups') {
        $editing = $groupEdit || (($_POST['action'] ?? '') === 'save_group');
        $groupId = (int)($groupEdit['id'] ?? $_POST['group_id'] ?? 0);
        $groupName = (string)($groupEdit['name'] ?? $_POST['name'] ?? '');
        $groupDescription = (string)($groupEdit['description'] ?? $_POST['description'] ?? '');
        $body .= '<div class="admin-columns"><section class="subpanel"><h2>'.($editing?'Gruppe bearbeiten':'Gruppe anlegen').'</h2><form method="post" action="index.php?page=admin&tab=groups"><input type="hidden" name="csrf" value="'.e(tok()).'"><input type="hidden" name="action" value="save_group"><input type="hidden" name="group_id" value="'.$groupId.'"><label>Gruppenname<input name="name" maxlength="100" required value="'.e($groupName).'"></label><label>Beschreibung<input name="description" maxlength="255" value="'.e($groupDescription).'"></label><button class="button">Gruppe speichern</button>'.($editing?' <a class="cancel" href="index.php?page=admin&tab=groups">Abbrechen</a>':'').'</form></section><section class="subpanel"><h2>Vorhandene Gruppen <span class="count">'.count($groups).'</span></h2>';
        if (!$groups) $body .= '<p class="muted">Noch keine Gruppen angelegt.</p>';
        foreach ($groups as $group) {
            $body .= '<div class="list-row"><div><strong>'.e($group['name']).'</strong><small>'.e($group['description'] ?: 'Keine Beschreibung').'</small></div><div class="row-actions"><a class="text-link" href="index.php?page=admin&tab=groups&edit_group='.(int)$group['id'].'">Bearbeiten</a><form method="post" action="index.php?page=admin&tab=groups"><input type="hidden" name="csrf" value="'.e(tok()).'"><input type="hidden" name="action" value="delete_group"><input type="hidden" name="group_id" value="'.(int)$group['id'].'"><button class="link-danger">Löschen</button></form></div></div>';
        }
        $body .= '</section></div>';
    } else {
        $editing = $userEdit || (($_POST['action'] ?? '') === 'save_user');
        $userId = (int)($userEdit['id'] ?? $_POST['user_id'] ?? 0);
        $username = (string)($userEdit['username'] ?? $_POST['username'] ?? '');
        $display = (string)($userEdit['display_name'] ?? $_POST['display_name'] ?? '');
        $userAdmin = (int)($userEdit['is_admin'] ?? (isset($_POST['is_admin']) ? 1 : 0)) === 1;
        if (($_POST['action'] ?? '') === 'save_user') $assigned = is_array($_POST['groups'] ?? null) ? array_map('intval', $_POST['groups']) : [];
        $body .= '<div class="admin-columns"><section class="subpanel"><h2>'.($editing?'Benutzer bearbeiten':'Benutzer anlegen').'</h2><form method="post" action="index.php?page=admin&tab=users"><input type="hidden" name="csrf" value="'.e(tok()).'"><input type="hidden" name="action" value="save_user"><input type="hidden" name="user_id" value="'.$userId.'"><label>Benutzername<input name="username" maxlength="64" required value="'.e($username).'"></label><label>Anzeigename<input name="display_name" maxlength="100" required value="'.e($display).'"></label><label>'.($editing?'Neues Passwort (leer lassen, um es beizubehalten)':'Passwort (mindestens 12 Zeichen)').'<input name="password" type="password" '.($editing?'':'required minlength="12"').' autocomplete="new-password"></label><label class="checkline"><input type="checkbox" name="is_admin" value="1"'.($userAdmin?' checked':'').'> Administratorrechte vergeben</label><p class="muted">Administratorrechte erlauben die Verwaltung. Sie sind unabhängig von den Gruppen.</p><label>Benutzergruppen <span class="hint">Mehrfachauswahl: Strg bzw. Cmd gedrückt halten</span><select name="groups[]" multiple size="5">';
        foreach ($groups as $group) $body .= '<option value="'.(int)$group['id'].'"'.(in_array((int)$group['id'],$assigned,true)?' selected':'').'>'.e($group['name']).'</option>';
        $body .= '</select></label><button class="button">Benutzer speichern</button>'.($editing?' <a class="cancel" href="index.php?page=admin&tab=users">Abbrechen</a>':'').'</form></section><section class="subpanel"><h2>Benutzerkonten</h2>';
        $sql = "SELECT u.id,u.username,u.display_name,u.is_admin,GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ', ') AS group_names FROM $users u LEFT JOIN $members m ON m.user_id=u.id LEFT JOIN $groupsTable g ON g.id=m.group_id GROUP BY u.id ORDER BY u.username";
        $rows = $pdo->query($sql)->fetchAll();
        if (!$rows) $body .= '<p class="muted">Noch keine Benutzer angelegt.</p>';
        foreach ($rows as $row) {
            $body .= '<div class="list-row"><div><strong>'.e($row['display_name']).'</strong><small>'.e($row['username']).' · '.((int)$row['is_admin']===1?'Administrator': 'Benutzer').' · Gruppen: '.e($row['group_names'] ?: 'keine').'</small></div><div class="row-actions"><a class="text-link" href="index.php?page=admin&tab=users&edit_user='.(int)$row['id'].'">Bearbeiten</a>';
            if ((int)$row['id'] !== (int)$admin['id']) {
                $body .= '<form method="post" action="index.php?page=admin&tab=users"><input type="hidden" name="csrf" value="'.e(tok()).'"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="'.(int)$row['id'].'"><button class="link-danger">Löschen</button></form>';
            }
            $body .= '</div></div>';
        }
        $body .= '</section></div>';
    }
    $body .= '</section>';
    view('Verwaltung', $body, true);
}

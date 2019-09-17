<?php
    if (isAdmin() && ($_POST["action"] === "reset")) {
        $success = true;

        logMessage("Reset action initiated", LogLevel::DEBUG);

        if ($_POST["teams"] == "true")
            $success &= execute("DELETE FROM teams WHERE login_name!=:login_name", array("login_name" => ADMIN_LOGIN_NAME));

        if ($_POST["contracts"] == "true")
            $success &= execute("DELETE FROM contracts");

        if ($_POST["chat"] == "true")
            $success &= execute("DELETE FROM chat");

        if ($_POST["privates"] == "true")
            $success &= execute("DELETE FROM privates");

        if ($_POST["auxiliary"] == "true") {
            $success &= execute("DELETE FROM solved");
            $success &= execute("DELETE FROM accepted");
            $success &= execute("DELETE FROM notifications");
            $success &= execute("DELETE FROM hide");
        }

        if ($success)
            die("OK");
        else {
            header("HTTP/1.1 500 Internal Server Error");
            die(DEBUG ? $_SESSION["conn_error"] : null);
        }
    }
    else if (isAdmin() && ($_POST["action"] === "delete")) {
        $success = false;

        if (isset($_POST["task_id"])) {
            logMessage("Delete task initiated", LogLevel::DEBUG, "'task_id' => " . $_POST["task_id"]);
            $success = deleteTask($_POST["task_id"]);
        }
        else if (isset($_POST["contract_id"])) {
            logMessage("Delete contract initiated", LogLevel::DEBUG, "'contract_id' => " . $_POST["contract_id"]);
            $success = deleteContract($_POST["contract_id"]);
        }
        else if (isset($_POST["team_id"])) {
            logMessage("Delete team initiated", LogLevel::DEBUG, "'team_id' => " . $_POST["team_id"]);
            $success = deleteTeam($_POST["team_id"]);
        }
        else if (isset($_POST["login_name"])) {
            logMessage("Delete team initiated", LogLevel::DEBUG, "'login_name' => '" . $_POST["login_name"] . "'");
            $success = execute("DELETE FROM teams WHERE login_name=:login_name", array("login_name" => $_POST["login_name"]));
        }
        else if (isset($_POST["notification_id"])) {
            $success = execute("DELETE FROM notifications WHERE notification_id=:notification_id", array("notification_id" => $_POST["notification_id"]));
        }

        if ($success)
            die("OK");
        else {
            header("HTTP/1.1 500 Internal Server Error");
            die(DEBUG ? $_SESSION["conn_error"] : null);
        }
    }
    else if (isAdmin() && ($_POST["action"] === "export")) {
        $output = shell_exec("mysqldump --user='" . MYSQL_USERNAME . "' --password='" . MYSQL_PASSWORD . "' --host='" . MYSQL_SERVER . "' '" . MYSQL_DATABASE . "' 2>&1 | grep -v 'Using a password on the command line interface can be insecure'");

        header("Content-Type: application/octet-stream");
        header("Content-Disposition: attachment; filename=\"" . MYSQL_DATABASE . ".sql\"");
        header("Content-Length: " . strlen($output));
        header("Connection: close");

        die($output);
    }
    else if (isAdmin() && ($_POST["action"] === "import")) {
        $output = shell_exec("mysql --user='" . MYSQL_USERNAME . "' --password='" . MYSQL_PASSWORD . "' --host='" . MYSQL_SERVER . "' '" . MYSQL_DATABASE . "' 2>&1 < " . $_FILES["import_file"]["tmp_name"] . " | grep -v 'Using a password on the command line interface can be insecure'");

        $success = strlen($output) === 0;

        if ($success)
            die("<html><head><meta http-equiv='refresh' content='1;url='" . PATHDIR . " /></head>OK</html>");
        else {
            header("HTTP/1.1 500 Internal Server Error");
            die($output);
        }
    }
    else if (isAdmin() && ($_POST["action"] === "notification") && isset($_POST["message"])) {
        $success = execute("INSERT INTO notifications(content, category) VALUES(:message, :category)", array("message" => $_POST["message"], "category" => NotificationCategories::everybody));

        if ($success) {
            execute("INSERT INTO chat(team_id, content, room) VALUES(:team_id, :content, :room)", array("team_id" => $_SESSION["team_id"], "content" => "Sent new notification to everybody: '" . $_POST["message"] . "'", "room" => DEFAULT_ROOM));
            die("OK");
        }
        else {
            header("HTTP/1.1 500 Internal Server Error");
            die(DEBUG ? $_SESSION["conn_error"] : null);
        }
    }
    else if ($_POST["action"] === "hide") {
        $success = execute("INSERT INTO hide(notification_id, team_id) VALUES(:notification_id, :team_id)", array("notification_id" => $_POST["notification_id"], "team_id" => $_SESSION["team_id"]));
        if ($success)
            die("OK");
        else {
            header("HTTP/1.1 500 Internal Server Error");
            die(DEBUG ? $_SESSION["conn_error"] : null);
        }
    }
    else if ($_POST["action"] === "update") {
        global $conn;

        if (isset($_POST["password"])) {

            if (isAdmin()) {
                $rows = fetchAll("SELECT password_hash FROM teams WHERE team_id=:team_id", array("team_id" => $_SESSION["team_id"]));

                if (!(isset($_POST["password_old"]) && (password_verify($_POST["password_old"], $rows[0]["password_hash"])))) {
                    if (strlen($_POST["password_old"]) > 2)
                        $masked = substr($_POST["password_old"], 0, 1) . str_repeat('*', strlen($_POST["password_old"]) - 2) . substr($_POST["password_old"], -1);
                    else
                        $masked = str_repeat('*', strlen($_POST["password_old"]));

                    logMessage("Wrong old password", LogLevel::WARNING, $_SESSION["login_name"] . ":" . $masked);
                    die();
                }
            }

            $success = execute("UPDATE teams SET password_hash=:password_hash WHERE team_id=:team_id", array("team_id" => $_SESSION["team_id"], "password_hash" => password_hash($_POST["password"], PASSWORD_BCRYPT)));

            if ($success) {
                logMessage("Password updated", LogLevel::DEBUG);
                die("OK");
            }
            else {
                header("HTTP/1.1 500 Internal Server Error");
                die(DEBUG ? $_SESSION["conn_error"] : null);
            }
        }
        else if (isAdmin() && isset($_POST["setting"])) {
            if ((strpos($_POST["setting"], "datetime_") === 0) && !preg_match("/[0-9]/", $_POST["value"]))
                $success = execute("DELETE FROM settings WHERE name=:name", array("name" => $_POST["setting"]));
            else
                $success = execute("INSERT INTO settings(name, value) VALUES(:name, :value) ON DUPLICATE KEY UPDATE value=:value", array("name" => $_POST["setting"], "value" => $_POST["value"]));

            if ($success)
                die("OK");
            else {
                header("HTTP/1.1 500 Internal Server Error");
                die(DEBUG ? $_SESSION["conn_error"] : null);
            }
        }
        else if (isAdmin() && isset($_POST["contract"])) {
            $contract = json_decode($_POST["contract"], true);

            logMessage("Update contract initiated", LogLevel::DEBUG);

            $success = true;
            $conn->beginTransaction();

            if ($contract["contract_id"] >= 0) {
                $success &= execute("UPDATE contracts SET title=:title, description=:description, categories=:categories, hidden=:hidden WHERE contract_id=:contract_id", array("title" => $contract["title"], "description" => $contract["description"], "categories" => $contract["categories"], "hidden" => intval($contract["hidden"]), "contract_id" => $contract["contract_id"]));
                $contract_id = $contract["contract_id"];
            }
            else {
                $success &= execute("INSERT INTO contracts(title, description, categories, hidden) VALUES (:title, :description, :categories, :hidden)", array("title" => $contract["title"], "description" => $contract["description"], "categories" => $contract["categories"], "hidden" => intval($contract["hidden"])));
                $contract_id = fetchScalar("SELECT MAX(contract_id) FROM contracts WHERE title=:title", array("title" => $contract["title"]));
            }

            $existing = fetchAll("SELECT task_id FROM tasks WHERE contract_id=:contract_id", array("contract_id" => $contract_id), PDO::FETCH_COLUMN);
            $updated = array();
            foreach ($contract["tasks"] as $task)
                array_push($updated, $task["task_id"]);

            $deleted = array_diff($existing, $updated);
            foreach ($deleted as $task_id)
                $success &= deleteTask($task_id);

            foreach ($contract["tasks"] as $task) {
                if ($task["task_id"] >= 0) {
                    $success &= execute("UPDATE tasks SET title=:title, description=:description, answer=:answer, cash=:cash, awareness=:awareness WHERE task_id=:task_id", array("title" => $task["title"], "description" => $task["description"], "answer" => $task["answer"], "cash" => $task["cash"], "awareness" => $task["awareness"], "task_id" => $task["task_id"]));
                    $task_id = $task["task_id"];
                }
                else {
                    $success &= execute("INSERT INTO tasks(contract_id, title, description, answer, cash, awareness) VALUES(:contract_id, :title, :description, :answer, :cash, :awareness)", array("contract_id" => $contract_id, "title" => $task["title"], "description" => $task["description"], "answer" => $task["answer"], "cash" => $task["cash"], "awareness" => $task["awareness"]));
                    $task_id = fetchScalar("SELECT MAX(task_id) FROM tasks WHERE title=:title", array("title" => $task["title"]));
                }

                execute("DELETE FROM options WHERE task_id=:task_id", array("task_id" => $task_id));
                $success &= execute("INSERT INTO options(task_id, note, is_regex, ignore_case, ignore_order) VALUES(:task_id, :note, :is_regex, :ignore_case, :ignore_order)", array("task_id" => $task_id, "note" => $task["note"], "is_regex" => intval($task["is_regex"]), "ignore_case" => intval($task["ignore_case"]), "ignore_order" => intval($task["ignore_order"])));
            }

            execute("DELETE FROM constraints WHERE contract_id=:contract_id", array("contract_id" => $contract["contract_id"]));
            if (isset($contract["constraints"])) {
                $min_cash = empty($contract["constraints"]["min_cash"]) ? null : $contract["constraints"]["min_cash"];
                $min_awareness = empty($contract["constraints"]["min_awareness"]) ? null : $contract["constraints"]["min_awareness"];

                if (!is_null($min_cash) || !is_null($min_awareness))
                    $success &= execute("INSERT INTO constraints(contract_id, min_cash, min_awareness) VALUES(:contract_id, :min_cash, :min_awareness)", array("contract_id" => $contract["contract_id"], "min_cash" => $min_cash, "min_awareness" => $min_awareness));
            }

            if ($success)
                $conn->commit();
            else
                $conn->rollback();

            if ($success) {
                $title = fetchScalar("SELECT title FROM contracts WHERE contract_id=:contract_id", array("contract_id" => $contract_id));
                logMessage("Contract updated", LogLevel::DEBUG, $title);
                die("OK");
            }
            else {
                logMessage("Contract update failed", LogLevel::ERROR, $_SESSION["conn_error"]);
                header("HTTP/1.1 500 Internal Server Error");
                die(DEBUG ? $_SESSION["conn_error"] : null);
            }
        }
        else if (isAdmin() && isset($_POST["team"])) {
            $team = json_decode($_POST["team"], true);

            if ($team["email"] && !filter_var($team["email"], FILTER_VALIDATE_EMAIL))
                die("Invalid email address");

            if ($team["team_id"] == -1) {
                $success = execute("INSERT INTO teams(login_name, full_name, country_code, email, password_hash) VALUES(:login_name, :full_name, :country_code, :email, :password_hash)", array("login_name" => $team["login_name"], "full_name" => $team["full_name"], "country_code" => $team["country_code"], "email" => $team["email"], "password_hash" => password_hash($team["password"], PASSWORD_BCRYPT)));
            }
            else {
                if (isset($team["password"]) && ($team["password"] != ""))
                    $success = execute("UPDATE teams SET full_name=:full_name, country_code=:country_code, email=:email, password_hash=:password_hash WHERE team_id=:team_id", array("team_id" => $team["team_id"], "full_name" => $team["full_name"], "country_code" => $team["country_code"], "email" => $team["email"], "password_hash" => password_hash($team["password"], PASSWORD_BCRYPT)));
                else
                    $success = execute("UPDATE teams SET full_name=:full_name, country_code=:country_code, email=:email WHERE team_id=:team_id", array("team_id" => $team["team_id"], "full_name" => $team["full_name"], "country_code" => $team["country_code"], "email" => $team["email"]));
            }

            if ($success) {
                logMessage("Team updated", LogLevel::DEBUG, $team["login_name"]);
                die("OK");
            }
            else {
                header("HTTP/1.1 500 Internal Server Error");
                die(DEBUG ? $_SESSION["conn_error"] : null);
            }
        }
    }
    else if ($_POST["action"] === "status") {
        if (isAdmin()) {
            $result = array();
        }
        else {
            $result = array("progress" => array("cash" => array(0), "awareness" => array(0)), "places" => array());
            $_ = array_values(getMomentum($_SESSION["team_id"]))[0];

            foreach (array("cash", "awareness") as $key) {
                foreach ($_[$key] as $current) {
                    array_push($result["progress"][$key], $current["y"]);
                }
            }

            foreach (getPlaces($_SESSION["team_id"]) as $key => $value)
                $result["places"][$key] = $value;
        }

        $result["team_name"] = fetchScalar("SELECT full_name FROM teams WHERE team_id=:team_id", array("team_id" => $_SESSION["team_id"]));
        $result["notifications"] = getNotifications($_SESSION["team_id"]);

        echo json_encode($result);
    }

    else if ($_POST["action"] === "momentum") {
        echo json_encode(getMomentum_new());
    }
    else if (($_POST["action"] === "push") && (isset($_POST["message"]))) {
        $room = isset($_POST["room"]) ? $_POST["room"] : DEFAULT_ROOM;
        $success = execute("INSERT INTO chat(team_id, content, room) VALUES(:team_id, :content, :room)", array("team_id" => $_SESSION["team_id"], "content" => $_POST["message"], "room" => $room));
        if ($success)
            die("OK");
        else {
            header("HTTP/1.1 500 Internal Server Error");
            die(DEBUG ? $_SESSION["conn_error"] : null);
        }
    }
    else if (($_POST["action"] === "private") && (isset($_POST["to"])) && (isset($_POST["message"]) || isset($_POST["cash"]))) {
        $cash = (isset($_POST["cash"]) && is_numeric($_POST["cash"])) ? intval($_POST["cash"]) : NULL;
        $to_id = fetchScalar("SELECT team_id FROM teams WHERE login_name=:login_name", array("login_name" => $_POST["to"]));
        $max = getScores($_SESSION["team_id"])["cash"];

        if ($_POST["to"] === ADMIN_LOGIN_NAME) {
            if (!SUPPORT_MESSAGES_ENABLED || !isset($_POST["message"]))
                die();
            else
                $message = $_POST["message"];
        }
        else
            $message = (PRIVATE_MESSAGES_ENABLED && isset($_POST["message"])) ? $_POST["message"] : NULL;

        if ((!is_null($cash)) && ((getSetting("transfers") === "false") || ($cash < 0)) && (!isAdmin()))
            $success = false;
        else if (!is_null($to_id) && ($_SESSION["team_id"] !== $to_id) && (isAdmin() || !(!is_null($cash) && ($cash > $max)) && !((is_null($cash) || $cash === 0) && (is_null($message) || $message === "")))) {
            $leader = getRankedTeams()[0];
            $from_name = fetchScalar("SELECT full_name FROM teams WHERE team_id=:team_id", array("team_id" => $_SESSION["team_id"]));
            $to_name = fetchScalar("SELECT full_name FROM teams WHERE team_id=:team_id", array("team_id" => $to_id));
            $success = execute("INSERT INTO privates(from_id, to_id, cash, message) VALUES(:from_id, :to_id, :cash, :message)", array("from_id" => $_SESSION["team_id"], "to_id" => $to_id, "cash" => $cash, "message" => $message));
            if ($success) {
                if ($cash) {
                    if (!isAdmin()) {
                        execute("INSERT INTO notifications(team_id, content, category) VALUES(:team_id, :content, :category)", array("team_id" => $to_id, "content" => (isAdmin() ? "'" : "Team '") . $from_name . (isAdmin() ? ($cash > 0 ? "' awarded you " : "' penalized you ") : "' sent you ") . $cash . "€" . (isAdmin() ? (" with a note '" . $message . "'") : ($message ? " with a message '" . $message . "'" : "")), "category" => NotificationCategories::received_private));
                        execute("INSERT INTO notifications(team_id, content, category) VALUES(:team_id, :content, :category)", array("team_id" => $_SESSION["team_id"], "content" => (isAdmin() ? ($cash > 0 ? "You awarded " : "You penalized ") : "You sent ") . $cash . "€" . (isAdmin() ? (" with a note '" . $message . "'") : ($message ? " with a message '" . $message . "'" : "")) . " to team '" . $to_name . "'", "category" => NotificationCategories::sent_private));
                    }

                    logMessage(isAdmin() ? ($cash > 0 ? "Award" : "Penalty") . " given" : "Cash sent", $cash > 0 ? LogLevel::INFO : LogLevel::WARNING, $cash . "€ => '" . $to_name . (isAdmin() ? ("' with a note '" . $message . "'") : ($message ? " with a message '" . $message . "'" : "")));

                    $_ = getRankedTeams()[0];
                    if ($_ != $leader) {
                        $team_name = fetchScalar("SELECT full_name FROM teams WHERE team_id=:team_id", array("team_id" => $_));
                        logMessage("Leader changed", LogLevel::INFO, $team_name);
                    }
                }
                else {
                    execute("INSERT INTO notifications(team_id, content, category) VALUES(:team_id, :content, :category)", array("team_id" => $to_id, "content" => "Team '" . $from_name . "' sent you a private message '" . $message . "'", "category" => NotificationCategories::received_private));
                    execute("INSERT INTO notifications(team_id, content, category) VALUES(:team_id, :content, :category)", array("team_id" => $_SESSION["team_id"], "content" => "You sent a private message '" . $message . "' to" . ($_POST["to"] === ADMIN_LOGIN_NAME ? "" : " team") . " '" . $to_name . "'", "category" => NotificationCategories::sent_private));
                }
            }
        }
        else
            $success = false;

        if ($success)
            die("OK");
        else {
            header("HTTP/1.1 500 Internal Server Error");
            die(DEBUG ? $_SESSION["conn_error"] : null);
        }
    }
    else if ($_POST["action"] === "pull") {
        $result = array("chat" => array(), "notifications" => 0);
        $room = isset($_POST["room"]) ? $_POST["room"] : DEFAULT_ROOM;

        $chat_id = isset($_POST["chat_id"]) ? intval($_POST["chat_id"]) : 0;
        $chat = fetchAll("SELECT message_id, chat.team_id AS team_id, login_name, country_code, content, UNIX_TIMESTAMP(chat.ts) AS ts FROM chat JOIN teams ON chat.team_id=teams.team_id WHERE message_id>:message_id AND room=:room ORDER BY ts ASC", array("message_id" => $chat_id, "room" => $room));

        foreach ($chat as $row) {
            if (($room == PRIVATE_ROOM) && ($_SESSION["team_id"] != $row["team_id"]))
                continue;

            $content = $row["content"];
            if (($room !== PRIVATE_ROOM) && (!isAdmin()))
                $content = preg_replace(FLAG_REGEX, FLAG_REDACTED, $content);

            $_ = array("id" => $row["message_id"], "team" => $row["login_name"], "country" => $row["country_code"], "content" => $content, "ts" => $row["ts"]);
            array_push($result["chat"], $_);
        }

        $result["notifications"] = count(getVisibleNotifications($_SESSION["team_id"]));

        echo json_encode($result);
    }
?>

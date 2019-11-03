<?php

class RemoveIPBanEvent extends Event
{
    public $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }
}

class AddIPBanEvent extends Event
{
    public $ip;
    public $reason;
    public $end;

    public function __construct(string $ip, string $reason, string $end)
    {
        $this->ip = trim($ip);
        $this->reason = trim($reason);
        $this->end = trim($end);
    }
}

class IPBan extends Extension
{
    public function get_priority(): int
    {
        return 10;
    }

    public function onInitExt(InitExtEvent $event)
    {
        global $config;
        $config->set_default_string(
            "ipban_message",
            '<p>IP <b>$IP</b> has been banned until <b>$DATE</b> by <b>$ADMIN</b> because of <b>$REASON</b>
<p>If you couldn\'t possibly be guilty of what you\'re banned for, the person we banned probably had a dynamic IP address and so do you.
<p>See <a href="http://whatismyipaddress.com/dynamic-static">http://whatismyipaddress.com/dynamic-static</a> for more information.
<p>$CONTACT'
        );
        $this->check_ip_ban();
    }

    public function onPageRequest(PageRequestEvent $event)
    {
        if ($event->page_matches("ip_ban")) {
            global $page, $user;
            if ($user->can(Permissions::BAN_IP)) {
                if ($event->get_arg(0) == "add" && $user->check_auth_token()) {
                    if (isset($_POST['ip']) && isset($_POST['reason']) && isset($_POST['end'])) {
                        if (empty($_POST['end'])) {
                            $end = null;
                        } else {
                            $end = $_POST['end'];
                        }
                        send_event(new AddIPBanEvent($_POST['ip'], $_POST['reason'], $end));

                        flash_message("Ban for {$_POST['ip']} added");
                        $page->set_mode(PageMode::REDIRECT);
                        $page->set_redirect(make_link("ip_ban/list"));
                    }
                } elseif ($event->get_arg(0) == "remove" && $user->check_auth_token()) {
                    if (isset($_POST['id'])) {
                        send_event(new RemoveIPBanEvent($_POST['id']));

                        flash_message("Ban removed");
                        $page->set_mode(PageMode::REDIRECT);
                        $page->set_redirect(make_link("ip_ban/list"));
                    }
                } elseif ($event->get_arg(0) == "list") {
                    $bans = (isset($_GET["all"])) ? $this->get_bans() : $this->get_active_bans();
                    $this->theme->display_bans($page, $bans);
                }
            } else {
                $this->theme->display_permission_denied();
            }
        }
    }

    public function onSetupBuilding(SetupBuildingEvent $event)
    {
        $sb = new SetupBlock("IP Ban");
        $sb->add_longtext_option("ipban_message", 'Message to show to banned users:<br>(with $IP, $DATE, $ADMIN, $REASON, and $CONTACT)');
        $event->panel->add_block($sb);
    }

    public function onPageSubNavBuilding(PageSubNavBuildingEvent $event)
    {
        global $user;
        if ($event->parent==="system") {
            if ($user->can(Permissions::BAN_IP)) {
                $event->add_nav_link("ip_bans", new Link('ip_ban/list'), "IP Bans", NavLink::is_active(["ip_ban"]));
            }
        }
    }

    public function onUserBlockBuilding(UserBlockBuildingEvent $event)
    {
        global $user;
        if ($user->can(Permissions::BAN_IP)) {
            $event->add_link("IP Bans", make_link("ip_ban/list"));
        }
    }

    public function onAddIPBan(AddIPBanEvent $event)
    {
        global $cache, $user, $database;
        $sql = "INSERT INTO bans (ip, reason, end_timestamp, banner_id) VALUES (:ip, :reason, :end, :admin_id)";
        $database->Execute($sql, ["ip"=>$event->ip, "reason"=>$event->reason, "end"=>strtotime($event->end), "admin_id"=>$user->id]);
        $cache->delete("ip_bans_sorted");
        log_info("ipban", "Banned {$event->ip} because '{$event->reason}' until {$event->end}");
    }

    public function onRemoveIPBan(RemoveIPBanEvent $event)
    {
        global $cache, $database;
        $ban = $database->get_row("SELECT * FROM bans WHERE id = :id", ["id"=>$event->id]);
        if ($ban) {
            $database->Execute("DELETE FROM bans WHERE id = :id", ["id"=>$event->id]);
            $cache->delete("ip_bans_sorted");
            log_info("ipban", "Removed {$ban['ip']}'s ban");
        }
    }

    public function onDatabaseUpgrade(DatabaseUpgradeEvent $event)
    {
        global $database;
        global $config;

        // shortcut to latest
        if ($config->get_int("ext_ipban_version") < 1) {
            $database->create_table("bans", "
				id SCORE_AIPK,
				banner_id INTEGER NOT NULL,
				ip SCORE_INET NOT NULL,
				end_timestamp INTEGER,
				reason TEXT NOT NULL,
				added SCORE_DATETIME NOT NULL DEFAULT SCORE_NOW,
				FOREIGN KEY (banner_id) REFERENCES users(id) ON DELETE CASCADE,
			");
            $database->execute("CREATE INDEX bans__end_timestamp ON bans(end_timestamp)");
            $config->set_int("ext_ipban_version", 8);
        }

        // ===

        if ($config->get_int("ext_ipban_version") < 1) {
            $database->Execute("CREATE TABLE bans (
				id int(11) NOT NULL auto_increment,
				ip char(15) default NULL,
				date SCORE_DATETIME default NULL,
				end SCORE_DATETIME default NULL,
				reason varchar(255) default NULL,
				PRIMARY KEY (id)
			)");
            $config->set_int("ext_ipban_version", 1);
        }

        if ($config->get_int("ext_ipban_version") == 1) {
            $database->execute("ALTER TABLE bans ADD COLUMN banner_id INTEGER NOT NULL AFTER id");
            $config->set_int("ext_ipban_version", 2);
        }

        if ($config->get_int("ext_ipban_version") == 2) {
            $database->execute("ALTER TABLE bans DROP COLUMN date");
            $database->execute("ALTER TABLE bans CHANGE ip ip CHAR(20) NOT NULL");
            $database->execute("ALTER TABLE bans CHANGE reason reason TEXT NOT NULL");
            $database->execute("CREATE INDEX bans__end ON bans(end)");
            $config->set_int("ext_ipban_version", 3);
        }

        if ($config->get_int("ext_ipban_version") == 3) {
            $database->execute("ALTER TABLE bans CHANGE end old_end DATE NOT NULL");
            $database->execute("ALTER TABLE bans ADD COLUMN end INTEGER");
            $database->execute("UPDATE bans SET end = UNIX_TIMESTAMP(old_end)");
            $database->execute("ALTER TABLE bans DROP COLUMN old_end");
            $database->execute("CREATE INDEX bans__end ON bans(end)");
            $config->set_int("ext_ipban_version", 4);
        }

        if ($config->get_int("ext_ipban_version") == 4) {
            $database->execute("ALTER TABLE bans CHANGE end end_timestamp INTEGER");
            $config->set_int("ext_ipban_version", 5);
        }

        if ($config->get_int("ext_ipban_version") == 5) {
            $database->execute("ALTER TABLE bans CHANGE ip ip VARCHAR(15)");
            $config->set_int("ext_ipban_version", 6);
        }

        if ($config->get_int("ext_ipban_version") == 6) {
            $database->Execute("ALTER TABLE bans ADD FOREIGN KEY (banner_id) REFERENCES users(id) ON DELETE CASCADE");
            $config->set_int("ext_ipban_version", 7);
        }

        if ($config->get_int("ext_ipban_version") == 7) {
            $database->execute($database->scoreql_to_sql("ALTER TABLE bans CHANGE ip ip SCORE_INET"));
            $database->execute($database->scoreql_to_sql("ALTER TABLE bans ADD COLUMN added SCORE_DATETIME NOT NULL DEFAULT SCORE_NOW"));
            $config->set_int("ext_ipban_version", 8);
        }

        if ($config->get_int("ext_ipban_version") == 8) {
            $database->execute($database->scoreql_to_sql("ALTER TABLE bans ADD COLUMN mode TEXT NOT NULL DEFAULT 'block'"));
            $config->set_int("ext_ipban_version", 9);
        }
    }

    private function check_ip_ban()
    {
        $remote = $_SERVER['REMOTE_ADDR'];
        $bans = $this->get_active_bans_sorted();

        // bans[0] = IPs
        if (isset($bans[0][$remote])) {
            $this->block($remote);  // never returns
        }

        // bans[1] = CIDR nets
        foreach ($bans[1] as $ip => $true) {
            if (ip_in_range($remote, $ip)) {
                $this->block($remote);  // never returns
            }
        }
    }

    private function block(string $remote)
    {
        global $config, $database;

        $prefix = ($database->get_driver_name() == DatabaseDriver::SQLITE ? "bans." : "");

        $bans = $this->get_active_bans();

        foreach ($bans as $row) {
            $ip = $row[$prefix."ip"];
            if (
                (strstr($ip, '/') && ip_in_range($remote, $ip)) ||
                ($ip == $remote)
            ) {
                $reason = $row[$prefix.'reason'];
                $admin = User::by_id($row[$prefix.'banner_id']);
                $date = date("Y-m-d", $row[$prefix.'end_timestamp']);
                $msg = $config->get_string("ipban_message");
                $msg = str_replace('$IP', $ip, $msg);
                $msg = str_replace('$DATE', $date, $msg);
                $msg = str_replace('$ADMIN', $admin->name, $msg);
                $msg = str_replace('$REASON', $reason, $msg);
                $contact_link = contact_link();
                if (!empty($contact_link)) {
                    $msg = str_replace('$CONTACT', "<a href='$contact_link'>Contact the staff (be sure to include this message)</a>", $msg);
                } else {
                    $msg = str_replace('$CONTACT', "", $msg);
                }
                header("HTTP/1.0 403 Forbidden");
                print "$msg";

                exit;
            }
        }
        log_error("ipban", "block($remote) called but no bans matched");
        exit;
    }

    private function get_bans()
    {
        global $database;
        $bans = $database->get_all("
			SELECT bans.*, users.name as banner_name
			FROM bans
			JOIN users ON banner_id = users.id
			ORDER BY added, end_timestamp, bans.id
		");
        if ($bans) {
            return $bans;
        } else {
            return [];
        }
    }

    private function get_active_bans()
    {
        global $database;

        $bans = $database->get_all("
			SELECT bans.*, users.name as banner_name
			FROM bans
			JOIN users ON banner_id = users.id
			WHERE (end_timestamp > :end_timestamp) OR (end_timestamp IS NULL)
			ORDER BY end_timestamp, bans.id
		", ["end_timestamp"=>time()]);

        if ($bans) {
            return $bans;
        } else {
            return [];
        }
    }

    // returns [ips, nets]
    private function get_active_bans_sorted()
    {
        global $cache;

        $cached = $cache->get("ip_bans_sorted");
        if ($cached) {
            return $cached;
        }

        $bans = $this->get_active_bans();
        $ips = []; # "0.0.0.0" => false);
        $nets = []; # "0.0.0.0/32" => false);
        foreach ($bans as $row) {
            if (strstr($row['ip'], '/')) {
                $nets[$row['ip']] = true;
            } else {
                $ips[$row['ip']] = true;
            }
        }

        $sorted = [$ips, $nets];
        $cache->set("ip_bans_sorted", $sorted, 600);
        return $sorted;
    }
}

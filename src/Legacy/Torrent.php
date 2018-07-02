<?php
/**
 * TorrentPier – Bull-powered BitTorrent tracker engine
 *
 * @copyright Copyright (c) 2005-2018 TorrentPier (https://torrentpier.com)
 * @link      https://github.com/torrentpier/torrentpier for the canonical source repository
 * @license   https://github.com/torrentpier/torrentpier/blob/master/LICENSE MIT License
 */

namespace TorrentPier\Legacy;

/**
 * Class Torrent
 * @package TorrentPier\Legacy
 */
class Torrent
{
    /**
     * Get torrent info by attach id
     *
     * @param int $attach_id
     *
     * @return array
     */
    private static function get_torrent_info($attach_id)
    {
        global $lang;

        $attach_id = (int)$attach_id;

        $sql = "
		SELECT
			a.post_id, d.physical_filename, d.extension, d.tracker_status,
			t.topic_first_post_id,
			p.poster_id, p.topic_id, p.forum_id,
			f.allow_reg_tracker
		FROM
			" . BB_ATTACHMENTS . " a,
			" . BB_ATTACHMENTS_DESC . " d,
			" . BB_POSTS . " p,
			" . BB_TOPICS . " t,
			" . BB_FORUMS . " f
		WHERE
			    a.attach_id = $attach_id
			AND d.attach_id = $attach_id
			AND p.post_id = a.post_id
			AND t.topic_id = p.topic_id
			AND f.forum_id = p.forum_id
		LIMIT 1
	";

        if (!$torrent = DB()->fetch_row($sql)) {
            bb_die($lang['INVALID_ATTACH_ID']);
        }

        return $torrent;
    }

    /**
     * Check that user has access to torrent download
     *
     * @param int $forum_id
     * @param int $poster_id
     *
     * @return bool|string
     */
    private static function torrent_auth_check($forum_id, $poster_id)
    {
        global $userdata, $lang;

        if (IS_ADMIN) {
            return true;
        }

        $is_auth = auth(AUTH_ALL, $forum_id, $userdata);

        if ($poster_id != $userdata['user_id'] && !$is_auth['auth_mod']) {
            bb_die($lang['NOT_MODERATOR']);
        } elseif (!$is_auth['auth_view'] || !$is_auth['auth_attachments'] || $attach_config['disable_mod']) {
            bb_die(sprintf($lang['SORRY_AUTH_READ'], $is_auth['auth_read_type']));
        }
        return $is_auth;
    }

    /**
     * Unregister torrent from tracker
     *
     * @param int $topic_id
     * @param string $mode
     */
    public static function tracker_unregister($topic_id, $mode = '')
    {
        global $lang, $bb_cfg;

        $attach_id = (int)$attach_id;
        $post_id = $topic_id = $forum_id = $info_hash = null;

        // Get torrent info
        if ($torrent = self::get_torrent_info($attach_id)) {
            $post_id = $torrent['post_id'];
            $topic_id = $torrent['topic_id'];
            $forum_id = $torrent['forum_id'];
        }

        if ($mode == 'request') {
            if (!$torrent) {
                bb_die($lang['TOR_NOT_FOUND']);
            }
            if (!$torrent['tracker_status']) {
                bb_die('Torrent already unregistered');
            }
            self::torrent_auth_check($forum_id, $torrent['poster_id']);
        }

        if (!$topic_id) {
            $sql = "SELECT topic_id FROM " . BB_BT_TORRENTS . " WHERE attach_id = $attach_id";

            if (!$result = DB()->sql_query($sql)) {
                bb_die('Could not query torrent information');
            }
            if ($row = DB()->sql_fetchrow($result)) {
                $topic_id = $row['topic_id'];
            }
        }

        // Unset DL-Type for topic
        if ($bb_cfg['bt_unset_dltype_on_tor_unreg'] && $topic_id) {
            $sql = "UPDATE " . BB_TOPICS . " SET topic_dl_type = " . TOPIC_DL_TYPE_NORMAL . " WHERE topic_id = $topic_id";

            if (!$result = DB()->sql_query($sql)) {
                bb_die('Could not update topics table #1');
            }
        }

        // Remove peers from tracker
        $sql = "DELETE FROM " . BB_BT_TRACKER . " WHERE topic_id = $topic_id";

        if (!DB()->sql_query($sql)) {
            bb_die('Could not delete peers');
        }

        // Ocelot
        if ($bb_cfg['ocelot']['enabled']) {
            if ($row = DB()->fetch_row("SELECT info_hash FROM " . BB_BT_TORRENTS . " WHERE attach_id = $attach_id LIMIT 1")) {
                $info_hash = $row['info_hash'];
            }
            self::ocelot_update_tracker('delete_torrent', array('info_hash' => rawurlencode($info_hash), 'id' => $topic_id));
        }

        // Delete torrent
        $sql = "DELETE FROM " . BB_BT_TORRENTS . " WHERE topic_id = $topic_id";

        if ($mode == 'request') {
            set_die_append_msg($forum_id, $topic_id);
            bb_die($lang['BT_UNREGISTERED']);
        }
    }

    /**
     * Change torrent status
     *
     * @param int $attach_id
     * @param int $new_tor_status
     */
    public static function change_tor_status($attach_id, $new_tor_status)
    {
        global $topic_id, $userdata, $lang;

        $attach_id = (int)$attach_id;
        $new_tor_status = (int)$new_tor_status;

        if (!$torrent = self::get_torrent_info($attach_id)) {
            bb_die($lang['TOR_NOT_FOUND']);
        }

        $topic_id = $torrent['topic_id'];

        self::torrent_auth_check($torrent['forum_id'], $torrent['poster_id']);

        DB()->query("
		UPDATE " . BB_BT_TORRENTS . " SET
			tor_status = $new_tor_status,
			checked_user_id = {$userdata['user_id']},
			checked_time = '" . TIMENOW . "'
		WHERE attach_id = $attach_id
		LIMIT 1
	");
    }

    /**
     * Set freeleech type for torrent
     *
     * @param int $attach_id
     * @param int $tor_status_gold
     */
    public static function change_tor_type($attach_id, $tor_status_gold)
    {
        global $topic_id, $lang, $bb_cfg;

        if (!$torrent = self::get_torrent_info($attach_id)) {
            bb_die($lang['TOR_NOT_FOUND']);
        }

        if (!IS_AM) {
            bb_die($lang['ONLY_FOR_MOD']);
        }

        $topic_id = $torrent['topic_id'];
        $tor_status_gold = (int)$tor_status_gold;
        $info_hash = null;

        DB()->query("UPDATE " . BB_BT_TORRENTS . " SET tor_type = $tor_status_gold WHERE topic_id = $topic_id");

        // Ocelot
        if ($bb_cfg['ocelot']['enabled']) {
            if ($row = DB()->fetch_row("SELECT info_hash FROM " . BB_BT_TORRENTS . " WHERE topic_id = $topic_id LIMIT 1")) {
                $info_hash = $row['info_hash'];
            }
            self::ocelot_update_tracker('update_torrent', array('info_hash' => rawurlencode($info_hash), 'freetorrent' => $tor_status_gold));
        }
    }

    /**
     * Register torrent on tracker
     *
     * @param int $topic_id
     * @param string $mode
     * @param int $tor_status
     * @param int $reg_time
     *
     * @return bool
     */
    public static function tracker_register($topic_id, $mode = '', $tor_status = TOR_NOT_APPROVED, $reg_time = TIMENOW)
    {
        global $bb_cfg, $lang, $reg_mode;

        $reg_mode = $mode;

        $forum_id = $torrent['forum_id'];
        $poster_id = $torrent['poster_id'];
        $info_hash = null;

        if ($torrent['extension'] !== TORRENT_EXT) {
            return self::torrent_error_exit($lang['NOT_TORRENT']);
        }
        if (!$torrent['allow_reg_tracker']) {
            return self::torrent_error_exit($lang['REG_NOT_ALLOWED_IN_THIS_FORUM']);
        }
        if ($post_id != $torrent['topic_first_post_id']) {
            return self::torrent_error_exit($lang['ALLOWED_ONLY_1ST_POST_REG']);
        }
        if ($torrent['tracker_status']) {
            return self::torrent_error_exit($lang['ALREADY_REG']);
        }
        if ($this_topic_torrents = self::get_registered_torrents($topic_id, 'topic')) {
            return self::torrent_error_exit($lang['ONLY_1_TOR_PER_TOPIC']);
        }

        self::torrent_auth_check($forum_id, $torrent['poster_id']);

        $filename =  '/' . $torrent['physical_filename'];
        $file_contents = file_get_contents($filename);

        if (!is_file($filename)) {
            return self::torrent_error_exit('File name error');
        }
        if (!file_exists($filename)) {
            return self::torrent_error_exit('File not exists');
        }
        if (!$tor = \Rych\Bencode\Bencode::decode($file_contents)) {
            return self::torrent_error_exit('This is not a bencoded file');
        }

        if ($bb_cfg['bt_disable_dht']) {
            $tor['info']['private'] = (int)1;
            $fp = fopen($filename, 'wb+');
            fwrite($fp, \Rych\Bencode\Bencode::encode($tor));
            fclose($fp);
        }

        $info = ($tor['info']) ? $tor['info'] : array();

        if (!isset($info['name'], $info['piece length'], $info['pieces']) || \strlen($info['pieces']) % 20 != 0) {
            return self::torrent_error_exit($lang['TORFILE_INVALID']);
        }

        $info_hash = pack('H*', sha1(\Rych\Bencode\Bencode::encode($info)));
        $info_hash_sql = rtrim(DB()->escape($info_hash), ' ');
        $info_hash_md5 = md5($info_hash);

        // Ocelot
        if ($bb_cfg['ocelot']['enabled']) {
            self::ocelot_update_tracker('add_torrent', array('info_hash' => rawurlencode($info_hash), 'id' => $topic_id, 'freetorrent' => 0));
        }

        if ($row = DB()->fetch_row("SELECT topic_id FROM " . BB_BT_TORRENTS . " WHERE info_hash = '$info_hash_sql' LIMIT 1")) {
            return self::torrent_error_exit($lang['BT_REG_FAIL_SAME_HASH'], TOPIC_URL . $row['topic_id']);
        }

        $totallen = 0;

        if (isset($info['length'])) {
            $totallen = (float)$info['length'];
        } elseif (isset($info['files']) && \is_array($info['files'])) {
            foreach ($info['files'] as $fn => $f) {
                $totallen += (float)$f['length'];
            }
        } else {
            return self::torrent_error_exit($lang['TORFILE_INVALID']);
        }

        $size = sprintf('%.0f', (float)$totallen);

        $columns = ' info_hash,       poster_id,  topic_id,  forum_id,    size,  reg_time,  tor_status';
        $values = "'$info_hash_sql', $poster_id, $topic_id, $forum_id, '$size', $reg_time, $tor_status";

        $sql = "INSERT INTO " . BB_BT_TORRENTS . " ($columns) VALUES ($values)";

        if (!DB()->sql_query($sql)) {
            $sql_error = DB()->sql_error();

            if ($sql_error['code'] == 1062) {
                // Duplicate entry

                return self::torrent_error_exit($lang['BT_REG_FAIL_SAME_HASH']);
            }
            bb_die('Could not register torrent on tracker');
        }

        // set DL-Type for topic
        if ($bb_cfg['bt_set_dltype_on_tor_reg']) {
            $sql = 'UPDATE ' . BB_TOPICS . ' SET topic_dl_type = ' . TOPIC_DL_TYPE_DL . " WHERE topic_id = $topic_id";

            if (!$result = DB()->sql_query($sql)) {
                bb_die('Could not update topics table #2');
            }
        }

        if ($bb_cfg['tracker']['tor_topic_up']) {
            DB()->query("UPDATE " . BB_TOPICS . " SET topic_last_post_time = GREATEST(topic_last_post_time, " . (TIMENOW - 3 * 86400) . ") WHERE topic_id = $topic_id");
        }

        if ($reg_mode == 'request' || $reg_mode == 'newtopic') {
            set_die_append_msg($forum_id, $topic_id);
            bb_die(sprintf($lang['BT_REGISTERED'], DL_URL . $topic_id));
        }

        return true;
    }

    /**
     * Delete torrent-file from tracker
     *
     * @param int $topic_id
     *
     * @return bool
     */
    public function delete_torrent($topic_id)
    {
        self::tracker_unregister($topic_id);
        //delete_attach($topic_id, 8);

        return true;
    }

    /**
     * Set passkey and send torrent to the browser
     *
     * @param array $t_data
     */
    public static function send_torrent_with_passkey($t_data)
    {
        global $userdata, $bb_cfg, $lang;

        $post_id = $poster_id = $passkey_val = '';
        $topic_id = $t_data['topic_id'];
        $poster_id = $t_data['topic_poster'];
        $user_id = $userdata['user_id'];

        // Get $topic_id
        $topic_id_sql = 'SELECT topic_id FROM ' . BB_POSTS . ' WHERE post_id = ' . (int)$post_id;
        if (!($topic_id_result = DB()->sql_query($topic_id_sql))) {
            bb_die('Could not query post information');
        }
        $topic_id_row = DB()->sql_fetchrow($topic_id_result);
        $topic_id = $topic_id_row['topic_id'];

        if (!$attachment['tracker_status']) {
            bb_die($lang['PASSKEY_ERR_TOR_NOT_REG']);
        }

        if (bf($userdata['user_opt'], 'user_opt', 'dis_passkey') && !IS_GUEST) {
            bb_die($lang['DISALLOWED']);
        }

        if ($bt_userdata = get_bt_userdata($user_id)) {
            $passkey_val = $bt_userdata['auth_key'];
        }

        if (!$passkey_val) {
            if (!$passkey_val = self::generate_passkey($user_id)) {
                bb_simple_die('Could not generate passkey');
            } elseif ($bb_cfg['ocelot']['enabled']) {
                self::ocelot_update_tracker('add_user', array('id' => $user_id, 'passkey' => $passkey_val));
            }
        }

        // Ratio limit for torrents dl
        $min_ratio = $bb_cfg['bt_min_ratio_allow_dl_tor'];

        if ($min_ratio && $user_id != $poster_id && ($user_ratio = get_bt_ratio($bt_userdata)) !== null) {
            if ($user_ratio < $min_ratio && $post_id) {
                $dl = DB()->fetch_row("
				SELECT dl.user_status
				FROM " . BB_POSTS . " p
				LEFT JOIN " . BB_BT_DLSTATUS . " dl ON dl.topic_id = p.topic_id AND dl.user_id = $user_id
				WHERE p.post_id = $post_id
				LIMIT 1
			");

                if (!isset($dl['user_status']) || $dl['user_status'] != DL_STATUS_COMPLETE) {
                    bb_die(sprintf($lang['BT_LOW_RATIO_FOR_DL'], round($user_ratio, 2), "search.php?dlu=$user_id&amp;dlc=1"));
                }
            }
        }

        // Announce URL
        $ann_url = $bb_cfg['bt_announce_url'];

        $filename = get_attach_path($topic_id, 8);
        if (!file_exists($filename)) {
            bb_simple_die($lang['NOT_FOUND']);
        }
        $file_contents = file_get_contents($filename);
        if (!$tor = \Rych\Bencode\Bencode::decode($file_contents)) {
            bb_die('This is not a bencoded file');
        }

        $announce = $bb_cfg['ocelot']['enabled'] ? (string)($bb_cfg['ocelot']['url'] . $passkey_val . "/announce") : (string)($ann_url . "?$passkey_key=$passkey_val");

        // Replace original announce url with tracker default
        if ($bb_cfg['bt_replace_ann_url'] || !isset($tor['announce'])) {
            $tor['announce'] = $announce;
        }

        // Delete all additional urls
        if ($bb_cfg['bt_del_addit_ann_urls'] || $bb_cfg['bt_disable_dht']) {
            unset($tor['announce-list']);
        } elseif (isset($tor['announce-list'])) {
            $tor['announce-list'] = array_merge($tor['announce-list'], array(array($announce)));
        }

        // Add retracker
        if (isset($bb_cfg['tracker']['retracker']) && $bb_cfg['tracker']['retracker']) {
            if (bf($userdata['user_opt'], 'user_opt', 'user_retracker') || IS_GUEST) {
                if (!isset($tor['announce-list'])) {
                    $tor['announce-list'] = array(
                        array($announce),
                        array($bb_cfg['tracker']['retracker_host'])
                    );
                } else {
                    $tor['announce-list'] = array_merge($tor['announce-list'], array(array($bb_cfg['tracker']['retracker_host'])));
                }
            }
        }

        // Add publisher & topic url
        $publisher_name = $bb_cfg['server_name'];
        $publisher_url = make_url(TOPIC_URL . $topic_id);

        $tor['publisher'] = (string)$publisher_name;
        unset($tor['publisher.utf-8']);

        $tor['publisher-url'] = (string)$publisher_url;
        unset($tor['publisher-url.utf-8']);

        $tor['comment'] = (string)$publisher_url;
        unset($tor['comment.utf-8']);

        // Send torrent
        $output = \Rych\Bencode\Bencode::encode($tor);
        $dl_fname = '[' . $bb_cfg['server_name'] . '].t' . $topic_id . '.torrent';

        if (!empty($_COOKIE['explain'])) {
            $out = "attach path: $filename<br /><br />";
            $tor['info']['pieces'] = '[...] ' . \strlen($tor['info']['pieces']) . ' bytes';
            $out .= print_r($tor, true);
            bb_die("<pre>$out</pre>");
        }

        header("Content-Type: application/x-bittorrent; name=\"$dl_fname\"");
        header("Content-Disposition: attachment; filename=\"$dl_fname\"");

        bb_exit($output);
    }

    /**
     * Generate and save passkey for user
     *
     * @param int $user_id
     * @param bool $force_generate
     *
     * @return bool|string
     */
    public static function generate_passkey($user_id, $force_generate = false)
    {
        global $bb_cfg, $lang, $sql;

        $user_id = (int)$user_id;

        // Check if user can change passkey
        if (!$force_generate) {
            $sql = "SELECT user_opt FROM " . BB_USERS . " WHERE user_id = $user_id LIMIT 1";

            if (!$result = DB()->sql_query($sql)) {
                bb_die('Could not query userdata for passkey');
            }
            if ($row = DB()->sql_fetchrow($result)) {
                if (bf($row['user_opt'], 'user_opt', 'dis_passkey')) {
                    bb_die($lang['NOT_AUTHORISED']);
                }
            }
        }

        for ($i = 0; $i < 20; $i++) {
            $passkey_val = make_rand_str(BT_AUTH_KEY_LENGTH);
            $old_passkey = null;

            if ($row = DB()->fetch_row("SELECT auth_key FROM " . BB_BT_USERS . " WHERE user_id = $user_id LIMIT 1")) {
                $old_passkey = $row['auth_key'];
            }

            // Insert new row
            DB()->query("INSERT IGNORE INTO " . BB_BT_USERS . " (user_id, auth_key) VALUES ($user_id, '$passkey_val')");
            if (DB()->affected_rows() == 1) {
                return $passkey_val;
            }

            // Update
            DB()->query("UPDATE IGNORE " . BB_BT_USERS . " SET auth_key = '$passkey_val' WHERE user_id = $user_id LIMIT 1");
            if (DB()->affected_rows() == 1) {
                // Ocelot
                if ($bb_cfg['ocelot']['enabled']) {
                    self::ocelot_update_tracker('change_passkey', array('oldpasskey' => $old_passkey, 'newpasskey' => $passkey_val));
                }
                return $passkey_val;
            }
        }
        return false;
    }

    /**
     * Remove user from tracker
     *
     * @param int $user_id
     *
     * @return bool
     */
    public static function tracker_rm_user($user_id)
    {
        return DB()->sql_query("DELETE FROM " . BB_BT_TRACKER . " WHERE user_id = " . (int)$user_id);
    }

    /**
     * Search and return registered torrents
     *
     * @param int $id
     * @param string $mode
     *
     * @return bool
     */
    private static function get_registered_torrents($id, $mode)
    {
        $field = ($mode == 'topic') ? 'topic_id' : 'post_id';

        $sql = "SELECT topic_id FROM " . BB_BT_TORRENTS . " WHERE $field = $id LIMIT 1";

        if (!$result = DB()->sql_query($sql)) {
            bb_die('Could not query torrent id');
        }

        if ($rowset = @DB()->sql_fetchrowset($result)) {
            return $rowset;
        }

        return false;
    }

    /**
     * Exit with error
     *
     * @param string $message
     *
     * @return bool
     */
    private static function torrent_error_exit($message)
    {
        global $reg_mode, $return_message, $lang;

        $msg = '';

        if (isset($reg_mode) && ($reg_mode == 'request' || $reg_mode == 'newtopic')) {
            if (isset($return_message)) {
                $msg .= $return_message . '<br /><br /><hr /><br />';
            }
            $msg .= '<b>' . $lang['BT_REG_FAIL'] . '</b><br /><br />';
        }

        bb_die($msg . $message);

        return true;
    }

    /**
     * Update torrent on Ocelot tracker
     *
     * @param string $action
     * @param array $updates
     *
     * @return bool
     */
    private static function ocelot_update_tracker($action, $updates)
    {
        global $bb_cfg;

        $get = $bb_cfg['ocelot']['secret'] . "/update?action=$action";

        foreach ($updates as $key => $value) {
            $get .= "&$key=$value";
        }

        $max_attempts = 3;
        $err = false;

        return !(self::ocelot_send_request($get, $max_attempts, $err) === false);
    }

    /**
     * Send request to the Ocelot traker
     *
     * @param string $get
     * @param int $max_attempts
     * @param bool $err
     *
     * @return bool|int
     */
    private static function ocelot_send_request($get, $max_attempts = 1, &$err = false)
    {
        global $bb_cfg;

        $header = "GET /$get HTTP/1.1\r\nConnection: Close\r\n\r\n";
        $attempts = $success = $response = 0;

        while (!$success && $attempts++ < $max_attempts) {

            // Send request
            $file = fsockopen($bb_cfg['ocelot']['host'], $bb_cfg['ocelot']['port'], $error_num, $error_string);
            if ($file) {
                if (fwrite($file, $header) === false) {
                    $err = "Failed to fwrite()";
                    continue;
                }
            } else {
                $err = "Failed to fsockopen() - $error_num - $error_string";
                continue;
            }

            // Check for response
            while (!feof($file)) {
                $response .= fread($file, 1024);
            }

            $data_end = strrpos($response, "\n");
            $status = substr($response, $data_end + 1);

            if ($status == "success") {
                $success = true;
            }
        }

        return $success;
    }
}

<?php

/*
 Thread necromancy game for FluxBB
 Copyright (c) 2015 Mark "zini" Mäkinen
 License: MIT (check LICENCE.txt)

 How to install:
    1. Run "schema.sql" with SQLite3 to create tables
    2. Set constants
      2.1 Set NECRO_PATH to the absolute path that points to the script folder (with trailing slash)
      2.2 Set NECRO_FORUM_URL to the FluxBB forum url (with trailing slash)
      2.3 Set NECRO_THREAD_ID to the game thread id
    3. Create update.txt ("touch update.txt")
    4. Run this file in CLI to update data
      4.1 Create cronjob (if you want to) to update data ("php5 necro.php"))
*/

define("NECRO_PATH",      "");
define("NECRO_FORUM_URL", "");
define("NECRO_THREAD_ID", 0);

//////////////////////////////////////////////////////////////////////////////////////////////////////

$db = new SQLite3(NECRO_PATH . "necro.db");

// Run data update if in CLI
if (php_sapi_name() == "cli") {

	// UPDATE DATA

	$page = simplexml_load_file(NECRO_FORUM_URL . "extern.php?action=feed&tid=" . NECRO_THREAD_ID . "&type=atom");
	if (!$page) exit();

	$entries = array();

	foreach ($page->entry as $entry) {
		$id_raw = $entry->id;
		preg_match("/pid=(\\d+)#/", $id_raw, $id_m);	// Match post id
		$id = intval($id_m[1]);
		$date_raw = $entry->updated;
		$date = date_create_from_format(DateTime::ATOM, $date_raw);
		$author = $entry->author->name;

		$entries[] = array("id" => $id, "date" => $date, "author" => $author);
	}

	for ($i = 0; $i < count($entries); $i++) {

		// Check if in database
		$q = $db->prepare("SELECT COUNT(*) FROM posts WHERE id = :id");
		$q->bindValue(":id", $entries[$i]["id"]);
		$res = $q->execute();
		$count = $res->fetchArray()[0];
		if ($count != 0) continue;

		if ($i + 1 > count($entries) - 1) {
			// Check database for previous message
			// If no match, this is the first message in the thread -- no points
			$q = $db->prepare("SELECT COUNT(*), author, date FROM posts WHERE date < :d LIMIT 1");
			$q->bindValue(":d", $entries[$i]["date"]->format("Y-m-d H:i:s"));
			$res = $q->execute();
			$res = $res->fetchArray();
			if ($res[0] != 0) {
				// Ignore doubleposts
				if ($entries[$i]["author"] == $res[1]) continue;

				// Get date
				$date_raw = $res[2];
				$date = date_create_from_format("Y-m-d H:i:s", $date_raw);
				$min = date_diff($entries[$i]["date"], $date)->i;
				$score = ceil(pow($min, 1.1));

				// Insert to database
				$q = $db->prepare("INSERT INTO posts (id, author, date) VALUES (:id, :a, :d)");
				$q->bindValue(":id", $entries[$i]["id"]);
				$q->bindValue(":a", $entries[$i]["author"]);
				$q->bindValue(":d", $entries[$i]["date"]->format("Y-m-d H:i:s"));
				$q->execute();

				add_score($db, $entries[$i]["author"], $score);

				print($score . " points to " . $entries[$i]["id"] . " (" . $entries[$i]["author"] . ")\n");
			}
		} else {

			// Ignore doubleposts
			if ($entries[$i]["author"] == $entries[$i+1]["author"]) continue;

			$min = date_diff($entries[$i]["date"], $entries[$i+1]["date"])->i;
			$score = ceil(pow($min, 1.1));
			// Insert to database
			$q = $db->prepare("INSERT INTO posts (id, author, date) VALUES (:id, :a, :d)");
			$q->bindValue(":id", $entries[$i]["id"]);
			$q->bindValue(":a", $entries[$i]["author"]);
			$q->bindValue(":d", $entries[$i]["date"]->format("Y-m-d H:i:s"));
			$q->execute();

			add_score($db, $entries[$i]["author"], $score);

			print($score . " points to " . $entries[$i]["id"] . " (" . $entries[$i]["author"] . ")\n");
		}
	}

	// Set "last updated"-date
	file_put_contents(NECRO_PATH . "update.txt", date("Y-m-d H:i:s"));

} else {	// If HTTP request

	// GET DATA & GENERATE IMAGE

	$q = $db->prepare("SELECT author, score, (SELECT COUNT(*) FROM posts WHERE author = score.author) AS post_count FROM score ORDER BY score DESC");
	$r = $q->execute();
	$res = array();
	while ($row = $r->fetchArray()) {
		$res[] = $row;
	}

	// Determine image height, longest author text and longest points text
	$img_height = ((count($res) + 1) * 12) + 24;
	$longest_author = 0;
	$longest_pts = 0;
	for ($i = 0; $i < count($res); $i++) {
		if (strlen($res[$i]["author"]) > $longest_author) $longest_author = strlen($res[$i]["author"]);
		if (strlen($res[$i]["score"]) > $longest_pts) $longest_pts = strlen($res[$i]["score"]);
	}

	// Create image
	$img = imagecreatetruecolor(400, $img_height);

	// Allocate colors
	$back  = imagecolorallocate($img, 34, 34, 34);
	$white = imagecolorallocate($img, 255, 255, 255);

	// Background
	imagefill($img, 0, 0, $back);

	if (count($res) == 0) {
		imagestring($img, 2, 5, 5, "No posts!", $white);
		$y = 5;
	} else {

		// Draw text
		$y = 5;
		for ($i = 0; $i < count($res); $i++) {

			$author = $res[$i]["author"];
			$score = $res[$i]["score"];
			$post_count = $res[$i]["post_count"];
			$ppp = round($score / $post_count, 2);	// Points per post, average

			$mid = "";
			for ($a = 0; $a < ($longest_author - strlen($author)) + 2; $a++) {
				$mid .= ".";
			}

			$start = "";
			for ($a = 0; $a < strlen(strval(count($res))) - strlen(strval($i+1)); $a++) {
				$start .= " ";
			}

			$pts_ws = "";
			for ($a = 0; $a < $longest_pts - strlen(strval($score)); $a++) {
				$pts_ws .= " ";
			}

			if ($post_count == 1) {
				$txt = $i+1 . ". " . $start . $author . " " . $mid . " " . $score . " pts" . $pts_ws . " (" . $post_count . " post / " . $ppp . " avg ppp)";
			} else {
				$txt = $i+1 . ". " . $start . $author . " " . $mid . " " . $score . " pts" . $pts_ws . " (" . $post_count . " posts / " . $ppp . " avg ppp)";
			}

			imagestring($img, 2, 5, $y, $txt, $white);
			$y += 12;
		}

	}

	imagestring($img, 2, 5, $y+12, "Last updated: " . file_get_contents(NECRO_PATH . "update.txt"), $white);

	header("Content-Type: image/png");
	imagepng($img);
	imagedestroy($img);

}

exit();

function add_score($db, $author, $score) {

	$q = $db->prepare("SELECT COUNT(*) FROM score WHERE author = :a");
	$q->bindValue(":a", $author);
	$res = $q->execute();
	$c = $res->fetchArray()[0];
	if ($c == 0) {
		// Insert
		$q = $db->prepare("INSERT INTO score (author, score) VALUES (:a, :s)");
		$q->bindValue(":s", $score);
		$q->bindValue(":a", $author);
		$q->execute();
	} else {
		// Add
		$q = $db->prepare("UPDATE score SET score = score + :s WHERE author = :a");
		$q->bindValue(":s", $score);
		$q->bindValue(":a", $author);
		$q->execute();
	}

}

?>

<?php
require_once "lib/adodb/adodb.inc.php";

class Querylet { // {{{
	var $sql;
	var $variables;
	
	public function querylet($sql, $variables=array()) {
		$this->sql = $sql;
		$this->variables = $variables;
	}

	public function append($querylet) {
		$this->sql .= $querylet->sql;
		$this->variables = array_merge($this->variables, $querylet->variables);
	}

	public function append_sql($sql) {
		$this->sql .= $sql;
	}

	public function add_variable($var) {
		$this->variables[] = $var;
	}
} // }}}

class Database {
	var $db;
	var $extensions;

	public function Database() {
		if(is_readable("config.php")) {
			require_once "config.php";
			$this->db = NewADOConnection($database_dsn);
			$this->db->SetFetchMode(ADODB_FETCH_ASSOC);
			$this->extensions = $this->db->GetAssoc("SELECT name, version FROM extensions");
		}
		else {
			header("Location: install.php");
			exit;
		}
	}

// misc {{{
	public function count_pages($tags=array()) {
		global $config;
		$images_per_page = $config->get_int('index_width') * $config->get_int('index_height');
		if(count($tags) == 0) {
			return ceil($this->db->GetOne("SELECT COUNT(*) FROM images") / $images_per_page);
		}
		else {
			$querylet = $this->build_search_querylet($tags);
			$result = $this->db->Execute($querylet->sql, $querylet->variables);
			return ceil($result->RecordCount() / $images_per_page);
		}
	}
// }}}
// extensions {{{
	public function set_extension_version($name, $version) {
		$this->extensions[$name] = $version;
		$this->db->GetRow("INSERT INTO extensions(name, version) VALUES (?, ?)", array($name, $version));
	}
	public function get_extension_version($name) {
		return (isset($this->extensions[$name]) ? $this->extensions[$name] : -1);
	}
// }}}
// tags {{{
	public function resolve_alias($tag) {
		$newtag = $this->db->GetOne("SELECT newtag FROM aliases WHERE oldtag=?", array($tag));
		if(!empty($newtag)) {
			return $newtag;
		} else {
			return $tag;
		}
	}

	public function sanitise($tag) {
		return preg_replace("/[\s?*]/", "", $tag);
	}

	private function build_search_querylet($terms) {
		$tag_search = new Querylet("0");
		$positive_tag_count = 0;
		$img_search = new Querylet("");

		foreach($terms as $term) {
			$negative = false;
			if((strlen($term) > 0) && ($term[0] == '-')) {
				$negative = true;
				$term = substr($term, 1);
			}
			
			$term = $this->resolve_alias($term);

			if(substr($term, 0, 5) == "size=") {
				$dim = substr($term, 5);
				$parts = explode('x', $dim);
				$args = array(int_escape($parts[0]), int_escape($parts[1]));
				$img_search->append(new Querylet("AND (width = ? AND height = ?)", $args));
			}
			else if(substr($term, 0, 5) == "size>") {
				$dim = substr($term, 5);
				$parts = explode('x', $dim);
				$args = array(int_escape($parts[0]), int_escape($parts[1]));
				$img_search->append(new Querylet("AND (width > ? AND height > ?)", $args));
			}
			else if(substr($term, 0, 5) == "size<") {
				$dim = substr($term, 5);
				$parts = explode('x', $dim);
				$args = array(int_escape($parts[0]), int_escape($parts[1]));
				$img_search->append(new Querylet("AND (width < ? AND height < ?)", $args));
			}
			else if(substr($term, 0, 6) == "ratio=") {
				$dim = substr($term, 6);
				$parts = explode(':', $dim);
				$args = array(int_escape($parts[0]), int_escape($parts[1]));
				$img_search->append(new Querylet("AND (width / height = ? / ?)", $args));
			}
			else if(substr($term, 0, 3) == "id<") {
				$img_search->append(new Querylet("AND (id < ?)", array(int_escape(substr($term, 3)))));
			}
			else if(substr($term, 0, 3) == "id>") {
				$img_search->append(new Querylet("AND (id > ?)", array(int_escape(substr($term, 3)))));
			}
			else if(substr($term, 0, 9) == "filesize<") {
				$img_search->append(new Querylet("AND (filesize < ?)", array(parse_shorthand_int(substr($term, 9)))));
			}
			else if(substr($term, 0, 9) == "filesize>") {
				$img_search->append(new Querylet("AND (filesize > ?)", array(parse_shorthand_int(substr($term, 9)))));
			}
			else {
				$term = str_replace("*", "%", $term);
				$term = str_replace("?", "_", $term);
				$sign = $negative ? "-" : "+";
				if($sign == "+") $positive_tag_count++;
				$tag_search->append(new Querylet(" $sign (tag LIKE ?)", array($term)));
			}
		}

		$database_fails = false; // MySQL 4.0 fails at subqueries
		if(count($tag_search->variables) == 0 || $database_fails) {
			$query = new Querylet("SELECT * FROM images ");
		}
		else {
			$subquery = new Querylet("
				SELECT *, SUM({$tag_search->sql}) AS score
				FROM images
				LEFT JOIN tags ON tags.image_id = images.id
				GROUP BY images.id
				HAVING score = ?",
				array_merge(
					$tag_search->variables,
					array($positive_tag_count)
				)
			);
			$query = new Querylet("SELECT * FROM ({$subquery->sql}) AS images ", $subquery->variables);
		}

		if(count($img_search->variables) > 0) {
			$query->append_sql("WHERE 1=1 ");
			$query->append($img_search);
		}

		return $query;
	}

	private function build_search_querylet_old($tags) { // {{{
		$querylet = new Querylet("SELECT images.*, SUM(");

		$tnum = 0;
		foreach($tags as $tag) {
			if(($tag != "") && ($tag[0] == '-')) continue;
			$querylet->append_sql($tnum == 0 ? "(" : " OR ");
			$querylet->append($this->term_to_querylet($tag));
			$tnum++;
		}
		$min_score = $tnum;
		if($tnum > 0) $querylet->append_sql(")");

		$tnum = 0;
		foreach($tags as $tag) {
			if(($tag == "") || ($tag[0] != '-')) continue;
			$querylet->append_sql($tnum == 0 ? "-(" : " OR ");
			$querylet->append($this->term_to_querylet(substr($tag, 1)));
			$tnum++;
		}
		if($tnum > 0) $querylet->append_sql(")");

		$querylet->append_sql(") AS score
			FROM tags
			LEFT JOIN images ON image_id=images.id
			GROUP BY images.id
			HAVING score >= ?
		");
		$querylet->add_variable($min_score);

		return $querylet;
	}
	
	private function term_to_querylet($term) {
		$term = $this->resolve_alias($term);

		if(substr($term, 0, 5) == "size:") {
			$dim = substr($term, 5);
			$parts = explode('x', $dim);
			return new Querylet("(width = ? AND height = ?)", array(int_escape($parts[0]), int_escape($parts[1])));
		}
		else if(substr($term, 0, 9) == "size-min:") {
			$dim = substr($term, 9);
			$parts = explode('x', $dim);
			return new Querylet("(width >= ? AND height >= ?)", array(int_escape($parts[0]), int_escape($parts[1])));
		}
		else if(substr($term, 0, 9) == "size-max:") {
			$dim = substr($term, 9);
			$parts = explode('x', $dim);
			return new Querylet("(width <= ? AND height <= ?)", array(int_escape($parts[0]), int_escape($parts[1])));
		}
		else {
			$term = str_replace("*", "%", $term);
			$term = str_replace("?", "_", $term);
			return new Querylet("(tag LIKE ?)", array($term));
		}
	} // }}}

	public function delete_tags_from_image($image_id) {
		$this->db->Execute("DELETE FROM tags WHERE image_id=?", array($image_id));
	}

	public function set_tags($image_id, $tags) {
		$tags = tag_explode($tags);

		$tags = array_map(array($this, 'resolve_alias'), $tags);
		$tags = array_map(array($this, 'sanitise'), $tags);
		$tags = array_unique($tags); // remove any duplicate tags

		// delete old
		$this->delete_tags_from_image($image_id);
		
		// insert each new tag
		foreach($tags as $tag) {
			$this->db->Execute("INSERT INTO tags(image_id, tag) VALUES(?, ?)", array($image_id, $tag));
		}
	}
// }}}
// images {{{
	public function get_images($start, $limit, $tags=array()) {
		$images = array();

		assert($start >= 0);
		assert($limit >  0);
		if($start < 0) $start = 0;
		if($limit < 1) $limit = 1;
		
		if(count($tags) == 0) {
			$result = $this->db->Execute("SELECT * FROM images ORDER BY id DESC LIMIT ?,?", array($start, $limit));
		}
		else {
			$querylet = $this->build_search_querylet($tags);
			$querylet->append(new Querylet("ORDER BY images.id DESC LIMIT ?,?", array($start, $limit)));
			$result = $this->db->Execute($querylet->sql, $querylet->variables);
		}
		
		while(!$result->EOF) {
			$images[] = new Image($result->fields);
			$result->MoveNext();
		}
		return $images;
	}

	public function get_next_image($id, $tags=array(), $next=true) {
		if($next) {
			$gtlt = "<";
			$dir = "DESC";
		}
		else {
			$gtlt = ">";
			$dir = "ASC";
		}

		if(count($tags) == 0) {
			$row = $this->db->GetRow("SELECT * FROM images WHERE id $gtlt ? ORDER BY id $dir", array((int)$id));
		}
		else {
			$tags[] = ($next ? "id<$id" : "id>$id");
			$dir    = ($next ? "DESC"   : "ASC");
			$querylet = $this->build_search_querylet($tags);
			$querylet->append_sql("ORDER BY id $dir");
			$row = $this->db->GetRow($querylet->sql, $querylet->variables);
		}
		
		return ($row ? new Image($row) : null);
	}

	public function get_prev_image($id, $tags=array()) {
		return $this->get_next_image($id, $tags, false);
	}

	public function get_image($id) {
		$image = null;
		$row = $this->db->GetRow("SELECT * FROM images WHERE id=?", array($id));
		return ($row ? new Image($row) : null);
	}

	public function remove_image($id) {
		$this->db->Execute("DELETE FROM images WHERE id=?", array($id));
	}
// }}}
// users {{{
	var $SELECT_USER = "SELECT *,time_to_sec(timediff(now(), joindate))/(60*60*24) AS days_old FROM users ";
	
	public function get_user($a=false, $b=false) {
		if($b == false) {
			return $this->get_user_by_id($a);
		}
		else {
			return $this->get_user_by_name_and_hash($a, $b);
		}
	}

	public function get_user_session($name, $session) {
		$row = $this->db->GetRow("{$this->SELECT_USER} WHERE name LIKE ? AND md5(concat(pass, ?)) = ?",
				array($name, $_SERVER['REMOTE_ADDR'], $session));
		return $row ? new User($row) : null;
	}

	public function get_user_by_id($id) {
		$row = $this->db->GetRow("{$this->SELECT_USER} WHERE id=?", array($id));
		return $row ? new User($row) : null;
	}
	
	public function get_user_by_name($name) {
		$row = $this->db->GetRow("{$this->SELECT_USER} WHERE name=?", array($name));
		return $row ? new User($row) : null;
	}

	public function get_user_by_name_and_hash($name, $hash) {
		$row = $this->db->GetRow("{$this->SELECT_USER} WHERE name LIKE ? AND pass = ?", array($name, $hash));
		return $row ? new User($row) : null;
	}
// }}}
}
?>

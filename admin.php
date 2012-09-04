<?
if(!defined('DOKU_INC')) define('DOKU_INC', dirname(dirname(dirname($_SERVER['SCRIPT_FILENAME']))).'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

# If you would like to use a custom PEAR directory for the libraries needed by
# this plugin, set it below.  If you have File_Archive installed in a default
# PEAR location (i.e. it was installed by your webhost), you comment out the
# next line of PHP:
define('CUSTOM_PEAR', DOKU_PLUGIN.'zip/pear/');

# Set this to the location this plugin should use for tmp files:
define('TMP_DIR', DOKU_PLUGIN.'zip/tmp/');

if (defined('CUSTOM_PEAR'))  ini_set('include_path', ini_get('include_path').PATH_SEPARATOR.CUSTOM_PEAR);

foreach(explode(PATH_SEPARATOR, ini_get('include_path')) as $file) {
	if (file_exists($file."/File/Archive.php")) {
		define("HAS_PEAR", true);
		require_once('File/Archive.php');
		break;
	}
}
require_once(DOKU_PLUGIN.'admin.php');

# Check to see if we can use cache:
/*foreach(explode(PATH_SEPARATOR, ini_get('include_path')) as $file) {
	if (file_exists($file."/Cache/Lite.php")) {
		require_once('Cache/Lite.php');
		define("USE_CACHE", true);
		break;
	}
}*/
if (!defined("USE_CACHE")) {
	define("USE_CACHE", false);
}


class admin_plugin_zip extends DokuWiki_Admin_Plugin {

	function getInfo() {
		return array(
			'author' => 'Andrew Pilsch',
			'email' => 'andrew@pilsch.com',
			'date' => '2006-05-31',
			'name' => 'admin plugin zip',
			'desc' => 'A plugin to create a zip archive of wiki data and to restore the wiki from a previous backup',
			'url' => 'http://wiki.pilsch.com/DokuZip',
		);
	}

	function getMenuSort() {
		return 50;
	}

	function handle() {
		if (!HAS_PEAR) {
			$this->msg = "It Appears You Have Not Installed the PEAR Libraries Needed For doku-zip";
			return;
		}
		$action = $_REQUEST['zip_action'];
		if (!isset($action)) {
			return null;
		}

		if ($action == 'backup') {
			$this->msg = $this->zip_create_backup();
		} else if ($action == 'restore') {
			$this->msg = $this->zip_restore_backup();
		}
	}

	function zip_create_backup() {
		global $conf;
		File_Archive::setOption("zipCompressionLevel",9);
		$stamp = date("Ymd");
		$zip_name = "{$conf['title']}-$stamp.zip";
		if (USE_CACHE) {
			$cache = new Cache_Lite(
				array(
					'lifeTime' => 3600,
					'cacheDir' => TMP_DIR
				)
			);
			File_Archive::setOption('cache', $cache);
		} else {
			if (!isset($_COOKIE['doku_zip_backup'])) {
				setcookie('doku_zip_backup', time(), time()+3600, '/');
			} else if(file_exists(TMP_DIR.$zip_name)) {
				
				# This prevents the problem with double sending zip
				# files that seems to be a result of the way the
				# doku admin plugin architecture is constructed.
				if (isset($_COOKIE['doku_zip_now'])) return;
				setcookie('doku_zip_now', time(), time()+5, '/');
			
				$_REQUEST['action'] = '';
				$dir = opendir(TMP_DIR);
				while($file = readdir($dir)) {
					if (preg_match("@.zip$@", $file) && $zip_name != $file) {
						unlink(TMP_DIR.$file);
					}
				}
				closedir($dir);
				File_Archive::extract(
					File_Archive::read(TMP_DIR.$zip_name."/"),
					File_Archive::toArchive($zip_name,
						File_Archive::toOutput()
					)
				);
				exit;
			}
		}

		if (substr($conf['savedir'],0,1) == "/") {
			$dir = $conf['savedir'];
		} else {
			$dir = DOKU_INC.'/'.$conf['savedir'];
		}

		# This prevents the problem with double sending zip
		# files that seems to be a result of the way the
		# doku admin plugin architecture is constructed.
		if (isset($_COOKIE['doku_zip_now'])) return;
		setcookie('doku_zip_now', time(), time()+5, '/');
			
		if (!USE_CACHE) {
			File_Archive::extract(
				File_Archive::filter(
					File_Archive::predOr(
						File_Archive::predEreg('\.txt$'),
						File_Archive::predEreg('\.log$'),
						File_Archive::predEreg('\.gz$')
					),
					File_Archive::read($dir)
				),
				$dest =TMP_DIR.$zip_name
			);

			$dir = opendir(TMP_DIR);
			while($file = readdir($dir)) {
				if (preg_match("@.zip$@", $file) && $zip_name != $file) {
					unlink(TMP_DIR.$file);
				}
			}
			closedir($dir);
		}

		File_Archive::extract(
			File_Archive::filter(
				File_Archive::predOr(
					File_Archive::predEreg('\.txt$'),
					File_Archive::predEreg('\.log$'),
					File_Archive::predEreg('\.gz$')
				),

				File_Archive::read($dir)
			),

			File_Archive::toArchive($zip_name,
				File_Archive::toOutput()
			)
		);
		exit;
	}

	function zip_restore_backup() {
		global $conf;

                if (substr($conf['savedir'],0,1) == "/") {
                        $dir = $conf['savedir'];
                } else {
                        $dir = DOKU_INC.'/'.$conf['savedir'];
                }

		if ($_FILES['zip_file']['error'] != 0) {
			return "Upload Error";
		}

		if (preg_match("@zip@", $_FILES['zip_file']['type'])) {
			$tmp = TMP_DIR . time().md5($_FILES['zip_file']['name']).'.zip';
			move_uploaded_file($_FILES['zip_file']['tmp_name'], $tmp);
			File_Archive::extract(
				File_Archive::read("$tmp/", $dir."/"),
				File_Archive::toFiles()
			);
			unlink($tmp);
		} else {
			print '<pre>'; print_r($_FILES); print '</pre>';
			return "Not a Zip";
		}
		return "Data Restored";
	}

	function html() {
		print $this->locale_xhtml('intro');

		if (isset($this->msg)) {
?>
<span id="zip_message"><?=$this->msg?></span>
<?
		}

		print $this->zip_create_backup_form();
		print "<div class='zip_space'></div>";
		print $this->zip_create_restore_form();
	}

	function zip_create_backup_form() {
?>
<span class="zip_form_box">
	<span class="zip_form_title"><?=$this->getLang('create_form_title')?></span>
	<center>
	<form method="post" action="<?=$_REQUEST['id']?>?do=<?=$_REQUEST['do']?>&page=<?=$_REQUEST['page']?>">
		<input type="hidden" name="do" value="<?=$_REQUEST['do']?>" />
		<input type="hidden" name="page" value="<?=$_REQUEST['page']?>" />
		<input type="hidden" name="id" value="<?$_REQUEST['id']?>" />
		<input type="hidden" name="zip_action" value="backup"/>

		<input type="submit" value="<?=$this->getLang('create_form_button')?>" />
	</form>
	</center>
</span>
<?
	}

	function zip_create_restore_form() {
?>
<span class="zip_form_box">
	<span class="zip_form_title"><?=$this->getLang('restore_form_title')?></span>
	<center>
	<form method="POST" action="<?=$_REQUEST['id']?>?do=<?=$_REQUEST['do']?>&page=<?=$_REQUEST['page']?>" enctype="multipart/form-data">
		<input type="hidden" name="MAX_FILE_SIZE" value="3000000" />
		<input type="hidden" name="do" value="<?=$_REQUEST['do']?>" />
		<input type="hidden" name="page" value="<?=$_REQUEST['page']?>" />
		<input type="hidden" name="id" value="<?$_REQUEST['id']?>" />
		<input type="hidden" name="zip_action" value="restore"/>

	<!--<label for="zip_file"><?=$this->getLang('restore_form_label')?></label>-->
		<input type="file" name="zip_file"/><br/><br/>
		<input type="submit" value="<?=$this->getLang('restore_form_button')?>"/>
	</form>
	</center>
<?
	}

}
?>

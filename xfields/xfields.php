<?php

// #==========================================================#
// # Plugin name: xfields [ Additional fields managment ]     #
// # Author: Vitaly A Ponomarev, vp7@mail.ru                  #
// # Allowed to use only with: Next Generation CMS            #
// #==========================================================#

// Protect against hack attempts
if (!defined('NGCMS')) die ('HAL');

// Load lang files
LoadPluginLang('xfields', 'config');


//
// XFields: Add/Modify attached files
function xf_modifyAttachedImages($dsID, $newsID, $xf, $attachList) {
	global $mysql, $config;

	// Init file/image processing libraries
	$fmanager = new file_managment();
	$imanager = new image_managment();


	$xdata = array();
	foreach ($xf['news'] as $id => $data) {
		// Attached images are processed in special way
		if ($data['type'] == 'images') {
			// Check if we should delete some images
			if (isset($_POST['xfields_'.$id.'_del']) && is_array($_POST['xfields_'.$id.'_del'])) {
				foreach ($_POST['xfields_'.$id.'_del'] as $key => $value) {
					// Allow to delete only images, that are attached to current news
					if ($value) {
						$xf = false;
						foreach ($attachList as $irow) {
							if ($irow['id'] == $key) {
								$xf = true; break;
							}
						}
						if (!$xf)
							continue;

						//print "NEED TO DEL [$key]<br/>\n";
						$fmanager->file_delete(array('type' => 'image', 'id' => $key));
					}
				}
			}
			// Check for attaches
			if (isset($_FILES['xfields_'.$id]) && isset($_FILES['xfields_'.$id]['name']) && is_array($_FILES['xfields_'.$id]['name'])) {
				foreach ($_FILES['xfields_'.$id]['name'] as $iId => $iName) {
					if ($_FILES['xfields_'.$id]['error'][$iId] > 0) {
						//print $iId." >>ERROR: ".$_FILES['xfields_'.$id]['error'][$iId]."<br/>\n";
						continue;
					}
					if ($_FILES['xfields_'.$id]['size'][$iId] == 0) {
						//print $iId." >>EMPTY IMAGE<br/>\n";
						continue;
					}

					// Upload file
					$up = $fmanager->file_upload(array('dsn' => true, 'linked_ds' => $dsID, 'linked_id' => $newsID, 'type' => 'image', 'http_var' => 'xfields_'.$id, 'http_varnum' => $iId, 'plugin' => 'xfields', 'pidentity' => $id));

					// Process upload error
					if (!is_array($up)) {
						continue;
					}
					//print "<pre>CREATED: ".var_export($up, true)."</pre>";
					// Check if we need to create preview
					$mkThumb  = $data['imgThumb'];
					$mkStamp  = $data['imgStamp'];
					$mkShadow = $data['imgShadow'];

					$stampFileName = '';
					if (file_exists(root.'trash/'.$config['wm_image'].'.gif')) {
						$stampFileName = root.'trash/'.$config['wm_image'].'.gif';
					} else if (file_exists(root.'trash/'.$config['wm_image'])) {
						$stampFileName = root.'trash/'.$config['wm_image'];
					}

					if ($mkThumb) {
						// Calculate sizes
						$tsx = $data['thumbWidth'];
						$txy = $data['thumbHeight'];

						if ($tsx < 10) {	$tsx = 150;		}
						if ($tsy < 10) {	$tsy = 150;		}

						$thumb = $imanager->create_thumb($config['attach_dir'].$up[2], $up[1], $tsx,$tsy, $config['thumb_quality']);
						//print "<pre>THUMB: ".var_export($thumb, true)."</pre>";
						if ($thumb) {
							//print "THUMB_OK<br/>";
							// If we created thumb - check if we need to transform it
							$stampThumb  = ($data['thumbStamp']  && ($stampFileName != ''))?1:0;
							$shadowThumb = $data['thumbShadow'];
							if ($shadowThumb || $stampThumb) {
								$stamp = $imanager->image_transform(
								array('image' => $config['attach_dir'].$up[2].'/thumb/'.$up[1],
								'stamp' => $stampThumb,
								'stamp_transparency' => $config['wm_image_transition'],
								'stamp_noerror' => true,
								'shadow' => $shadowThumb,
								'stampfile' => $stampFileName));
							}
						}
					}

					// Now write info about image into DB
					if (is_array($sz = $imanager->get_size($config['attach_dir'].$up[2].'/'.$up[1]))) {
						$fmanager->get_limits($type);


						// Gather filesize for thumbinals
						$thumb_size_x = 0;
						$thumb_size_y = 0;
						if (is_array($thumb) && is_readable($config['attach_dir'].$up[2].'/thumb/'.$up[1]) && is_array($szt = $imanager->get_size($config['attach_dir'].$up[2].'/thumb/'.$up[1]))) {
							$thumb_size_x = $szt[1];
							$thumb_size_y = $szt[2];
						}
						$mysql->query("update ".prefix."_".$fmanager->tname." set width=".db_squote($sz[1]).", height=".db_squote($sz[2]).", preview=".db_squote(is_array($thumb)?1:0).", p_width=".db_squote($thumb_size_x).", p_height=".db_squote($thumb_size_y).", stamp=".db_squote(is_array($stamp)?1:0)." where id = ".db_squote($up[0]));
					}

				}
			}
		}
	}
}


// Perform replacements while showing news
class XFieldsNewsFilter extends NewsFilter {
	function addNewsForm(&$tvars) {
		global $lang, $tpl, $twig, $catz;

		// Load config
		$xf = xf_configLoad();
		if (!is_array($xf))
			return false;

		$output = '';
		$xfEntries = array();

		if (is_array($xf['news']))
			foreach ($xf['news'] as $id => $data) {
				$xfEntry = array(
					'title'		=>	$data['title'],
					'id'		=>	$id,
					'required'	=>	$lang['xfields_fld_'.($data['required']?'required':'optional')],
					'flags'		=>	array(
						'required'	=>	$data['required']?true:false,
					),
				);


				switch ($data['type']) {
					case 'text'  : 	$val = '<input type="text" id="form_xfields_'.$id.'" name="xfields['.$id.']" title="'.$data['title'].'" value="'.secure_html($data['default']).'"/>';
									$xfEntry['input'] = $val;
									$xfEntries[] = $xfEntry;
									break;

					case 'select': 	$val = '<select name="xfields['.$id.']" id="form_xfields_'.$id.'" >';
									if (!$data['required']) $val .= '<option value=""></option>';
									if (is_array($data['options']))
										foreach ($data['options'] as $k => $v)
											$val .= '<option value="'.secure_html(($data['storekeys'])?$k:$v).'"'.((($data['storekeys'] && $data['default'] == $k)||(!$data['storekeys'] && $data['default'] == $v))?' selected':'').'>'.$v.'</option>';
									$val .= '</select>';
									$xfEntry['input'] = $val;
									$xfEntries[] = $xfEntry;
									break;
					case 'textarea'  :	$val = '<textarea cols="30" rows="5" name="xfields['.$id.']" id="form_xfields_'.$id.'" >'.$data['default'].'</textarea>';
									$xfEntry['input'] = $val;
									$xfEntries[] = $xfEntry;
									break;
					case 'images'	:
						$iCount = 0;
						$input = '';
						$tVars = array( 'images' => array());

						// Show entries for allowed number of attaches
						for ($i = $iCount+1; $i <= intval($data['maxCount']); $i++) {
							$tImage = array(
								'number'	=>	$i,
								'id'		=>	$id,
								'flags'		=> array(
									'exist'		=> false,
								),
							);
							$tVars['images'][] = $tImage;
						}

						// Make template
						$xt = $twig->loadTemplate('plugins/xfields/tpl/ed_entry.image.tpl');
						$val = $xt->render($tVars);
						$xfEntry['input'] = $val;
						$xfEntries[] = $xfEntry;
						break;
				}
			}

		$xfCategories = array();
		foreach ($catz as $cId => $cData) {
			$xfCategories[$cData['id']] = $cData['xf_group'];
		}

		$tVars = array(
			'entries'	=>	$xfEntries,
			'xfGC'		=>	json_encode(arrayCharsetConvert(0, $xf['grp.news'])),
			'xfCat'		=>	json_encode(arrayCharsetConvert(0, $xfCategories)),
			'xfList'	=>	json_encode(arrayCharsetConvert(0, array_keys($xf['news']))),
		);
		$xt = $twig->loadTemplate('plugins/xfields/tpl/add_news.tpl');
		$tvars['vars']['plugin_xfields'] .= $xt->render($tVars);;


//		$tv = array ( 'vars' => array( 'entries' => $output));
//		$tpl -> template('add_news', extras_dir.'/xfields/tpl');
//		$tpl -> vars('add_news', $tv);
//		$tvars['vars']['plugin_xfields'] = $tpl -> show('add_news');
		return 1;
	}
	function addNews(&$tvars, &$SQL) {
		global $lang, $twig, $twigLoader;
		// Load config
		$xf = xf_configLoad();
		if (!is_array($xf))
			return 1;

		$rcall = $_REQUEST['xfields'];
		if (!is_array($rcall)) $rcall = array();

		$xdata = array();
		foreach ($xf['news'] as $id => $data) {
			if ($data['type'] == 'images') { continue; }
			// Fill xfields. Check that all required fields are filled
			if ($rcall[$id] != '') {
				$xdata[$id] = $rcall[$id];
			} else if ($data['required']) {
				msg(array("type" => "error", "text" => str_replace('{field}', $id, $lang['xfields_msge_emptyrequired'])));
				return 0;
			}
			// Check if we should save data into separate SQL field
			if ($data['storage'] && ($rcall[$id] != ''))
				$SQL['xfields_'.$id] = $rcall[$id];
		}

	    $SQL['xfields']   = xf_encode($xdata);
		return 1;
	}
	function addNewsNotify(&$tvars, $SQL, $newsid) {

		// Load config
		$xf = xf_configLoad();
		if (!is_array($xf))
			return 1;

		xf_modifyAttachedImages(1, $newsid, $xf, array());
		return 1;
	}

	function editNewsForm($newsID, $SQLold, &$tvars) {
		global $lang, $tpl, $catz, $config, $twig, $twigLoader;
		//print "<pre>".var_export($lang, true)."</pre>";
		// Load config
		$xf = xf_configLoad();
		if (!is_array($xf))
			return false;

		// Fetch xfields data
		$xdata = xf_decode($SQLold['xfields']);
		if (!is_array($xdata))
			return false;

		$output = '';
		$xfEntries = array();

		foreach ($xf['news'] as $id => $data) {
			$xfEntry = array(
				'title'		=>	$data['title'],
				'id'		=>	$id,
				'required'	=>	$lang['xfields_fld_'.($data['required']?'required':'optional')],
				'flags'		=>	array(
					'required'	=>	$data['required']?true:false,
				),
			);
			switch ($data['type']) {
				case 'text'  : 	$val = '<input type="text" name="xfields['.$id.']"  id="form_xfields_'.$id.'" title="'.$data['title'].'" value="'.secure_html($xdata[$id]).'" />';
								$xfEntry['input'] = $val;
								$xfEntries[] = $xfEntry;
								break;
				case 'select': 	$val = '<select name="xfields['.$id.']" id="form_xfields_'.$id.'" >';
								if (!$data['required']) $val .= '<option value="">&nbsp;</option>';
								if (is_array($data['options']))
									foreach ($data['options'] as $k => $v) {
										$val .= '<option value="'.secure_html(($data['storekeys'])?$k:$v).'"'.((($data['storekeys'] && ($xdata[$id] == $k))||(!$data['storekeys'] && ($xdata[$id] == $v)))?' selected':'').'>'.$v.'</option>';
									}
								$val .= '</select>';
								$xfEntry['input'] = $val;
								$xfEntries[] = $xfEntry;
								break;
				case 'textarea'	:
								$val = '<textarea cols="30" rows="4" name="xfields['.$id.']" id="form_xfields_'.$id.'">'.$xdata[$id].'</textarea>';
								$xfEntry['input'] = $val;
								$xfEntries[] = $xfEntry;
								break;
				case 'images'	:
					// First - show already attached images
					$iCount = 0;
					$input = '';
					$tVars = array( 'images' => array());

					//$tpl -> template('ed_entry.image', extras_dir.'/xfields/tpl');
					if (is_array($SQLold['#images'])) {
						foreach ($SQLold['#images'] as $irow) {
							// Skip images, that are not related to current field
							if (($irow['plugin'] != 'xfields') || ($irow['pidentity'] != $id)) continue;

							// Show attached image
							$iCount++;

							$tImage = array(
								'number'	=>	$iCount,
								'id'		=>	$id,
								'preview'	=>	array(
									'width'		=>	$irow['p_width'],
									'height'	=>	$irow['p_height'],
									'url' 		=>	$config['attach_url'].'/'.$irow['folder'].'/thumb/'.$irow['name'],
								),
								'image'		=>	array(
									'id'		=> $irow['id'],
									'number'	=> $iCount,
									'url'		=> $config['attach_url'].'/'.$irow['folder'].'/'.$irow['name'],
									'width'		=> $irow['width'],
									'height'	=> $irow['height'],
								),
								'flags'		=> array(
									'preview'	=> $irow['preview']?true:false,
									'exist'		=> true,
								),
							);
							$tVars['images'][] = $tImage;
						}
					}

					// Second - show entries for allowed number of attaches
					for ($i = $iCount+1; $i <= intval($data['maxCount']); $i++) {
						$tImage = array(
							'number'	=>	$i,
							'id'		=>	$id,
							'flags'		=> array(
								'exist'		=> false,
							),
						);
						$tVars['images'][] = $tImage;
					}

					// Make template
					$xt = $twig->loadTemplate('plugins/xfields/tpl/ed_entry.image.tpl');
					$val = $xt->render($tVars);
					$xfEntry['input'] = $val;
					$xfEntries[] = $xfEntry;
					break;
			}
		}
		$xfCategories = array();
		foreach ($catz as $cId => $cData) {
			$xfCategories[$cData['id']] = $cData['xf_group'];
		}

		$tVars = array(
			'entries'	=>	$xfEntries,
			'xfGC'		=>	json_encode(arrayCharsetConvert(0, $xf['grp.news'])),
			'xfCat'		=>	json_encode(arrayCharsetConvert(0, $xfCategories)),
			'xfList'	=>	json_encode(arrayCharsetConvert(0, array_keys($xf['news']))),
		);
		$xt = $twig->loadTemplate('plugins/xfields/tpl/ed_news.tpl');
		$tvars['vars']['plugin_xfields'] .= $xt->render($tVars);;

		return 1;
	}
	function editNews($newsID, $SQLold, &$SQLnew, &$tvars) {
		global $lang, $config, $mysql;

		// Load config
		$xf = xf_configLoad();
		if (!is_array($xf))
			return 1;

		$rcall = $_POST['xfields'];
		if (!is_array($rcall)) $rcall = array();


		// Manage attached images
		xf_modifyAttachedImages(1, $newsID, $xf, $SQLold['#images']);

		// Init file/image processing libraries
		$fmanager = new file_managment();
		$imanager = new image_managment();


		$xdata = array();
		foreach ($xf['news'] as $id => $data) {
			// Attached images are processed in special way
			if ($data['type'] == 'images') {
				continue;
			}

			if ($rcall[$id] != '') {
				$xdata[$id] = $rcall[$id];
			} else if ($data['required']) {
				msg(array("type" => "error", "text" => str_replace('{field}', $id, $lang['xfields_msge_emptyrequired'])));
				return 0;
			}
			// Check if we should save data into separate SQL field
			if ($data['storage'])
				$SQLnew['xfields_'.$id] = $rcall[$id];
		}

	    $SQLnew['xfields']   = xf_encode($xdata);
		return 1;
	}

	// Show news call :: processor (call after all processing is finished and before show)
	function showNews($newsID, $SQLnews, &$tvars, $mode = array()) {
		// Try to load config. Stop processing if config was not loaded
		if (($xf = xf_configLoad()) === false) return;

		$fields = xf_decode($SQLnews['xfields']);
		$content = $SQLnews['content'];

		if (is_array($xf['news']))
			foreach ($xf['news'] as $k => $v) {
				$kp = preg_quote($k, "'");
				$xfk = isset($fields[$k])?$fields[$k]:'';
				$tvars['regx']["'\[xfield_".$kp."\](.*?)\[/xfield_".$kp."\]'is"] = ($xfk == "")?"":"$1";
				$tvars['vars']['[xvalue_'.$k.']'] = ($v['type'] == 'textarea')?'<br/>'.(str_replace("\n","<br/>\n",$xfk).(strlen($xfk)?'<br/>':'')):$xfk;
			}
		$SQLnews['content'] = $content;
	}
}

class XFieldsFilterAdminCategories extends FilterAdminCategories{
	function addCategory(&$tvars, &$SQL) {
		$SQL['xf_group'] = $_REQUEST['xf_group'];
		return 1;
	}

	function addCategoryForm(&$tvars) {
		global $lang;
		loadPluginLang('xfields', 'config', '', '', ':');

		// Get config
		$xf = xf_configLoad();

		// Prepare select
		$ms = '<select name="xf_group"><option value="">** ��� ���� **</option>';
		foreach ($xf['grp.news'] as $k => $v) {
			$ms .= '<option value="'.$k.'">'.$k.' ('.$v['title'].')</option>';
		}

		$tvars['vars']['extend'] .= '<tr><td width="70%" class="contentEntry1">'.$lang['xfields:categories.group'].'<br/><small>'.$lang['xfields:categories.group#desc'].'</small></td><td width="30%" class="contentEntry2">'.$ms.'</td></tr>';
		return 1;
	}


	function editCategoryForm($categoryID, $SQL, &$tvars) {
		global $lang;
		loadPluginLang('xfields', 'config', '', '', ':');

		// Get config
		$xf = xf_configLoad();

		// Prepare select
		$ms = '<select name="xf_group"><option value="">** ��� ���� **</option>';
		foreach ($xf['grp.news'] as $k => $v) {
			$ms .= '<option value="'.$k.'"'.(($SQL['xf_group'] == $k)?' selected="selected"':'').'>'.$k.' ('.$v['title'].')</option>';
		}

		$tvars['vars']['extend'] .= '<tr><td width="70%" class="contentEntry1">'.$lang['xfields:categories.group'].'<br/><small>'.$lang['xfields:categories.group#desc'].'</small></td><td width="30%" class="contentEntry2">'.$ms.'</td></tr>';
		return 1;
	}

	function editCategory($categoryID, $SQL, &$SQLnew, &$tvars) {
		$SQLnew['xf_group'] = $_REQUEST['xf_group'];
		return 1;
	}
}


register_filter('news','xfields', new XFieldsNewsFilter);
register_admin_filter('categories', 'xfields', new XFieldsFilterAdminCategories);


// Global XF variables
$XF = array();		// $XF - array with configuration
$XF_loaded = 0;		// $XF_loaded - flag if config is loaded


// Load fields definition
function xf_configLoad() {
	global $lang, $XF, $XF_loaded;

	if ($XF_loaded) return $XF;
	if (!($confdir = get_plugcfg_dir('xfields'))) return false;

	if (!file_exists($confdir.'/config.php')) {
		$XF_loaded = 1;
		return array( 'news' => array());
	}
	include $confdir.'/config.php';
	$XF_loaded = 1;
	$XF = is_array($xarray)?$xarray:array( 'news' => array());
	return $XF;
}

// Save fields definition
function xf_configSave($xf = null) {
	global $lang, $XF, $XF_loaded;

	if (!$XF_loaded) return false;
	if (!($confdir = get_plugcfg_dir('xfields'))) return false;

	// Open config
	if (!($fn = fopen($confdir.'/config.php', 'w'))) return false;

	// Write config
	fwrite($fn, "<?php\n\$xarray = ".var_export(is_array($xf)?$xf:$XF, true).";\n");
	fclose($fn);
	return true;
}

// Decode fields from text
function xf_decode($text){

	if ($text == '') return array();

	// MODERN METHOD
	if (substr($text,0,4) == "SER|") return unserialize(substr($text,4));

	// OLD METHOD. OBSOLETE but supported for reading
	$xfieldsdata = explode("||", $text);

	foreach ($xfieldsdata as $xfielddata) {
		list($xfielddataname, $xfielddatavalue) = explode("|", $xfielddata);
		$xfielddataname = str_replace("&#124;", "|", $xfielddataname);
		$xfielddataname = str_replace("__NEWL__", "\r\n", $xfielddataname);
		$xfielddatavalue = str_replace("&#124;", "|", $xfielddatavalue);
		$xfielddatavalue = str_replace("__NEWL__", "\r\n", $xfielddatavalue);
		$data[$xfielddataname] = $xfielddatavalue;
	}
	return $data;
}

// Encode fields into text
function xf_encode($fields){
	if (!is_array($fields)) return '';
	return 'SER|'.serialize($fields);
}
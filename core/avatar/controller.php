<?php
/**
 * Copyright (c) 2013 Christopher Schäpers <christopher@schaepers.it>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\Core\Avatar;

class Controller {
	public static function getAvatar($args) {
		if (!\OC_User::isLoggedIn()) {
			$l = new \OC_L10n('core');
			header("HTTP/1.0 403 Forbidden");
			\OC_Template::printErrorPage($l->t("Permission denied"));
			return;
		}

		$user = stripslashes($args['user']);
		$size = (int)$args['size'];
		if ($size > 2048) {
			$size = 2048;
		}
		// Undefined size
		elseif ($size === 0) {
			$size = 64;
		}

		$avatar = new \OC_Avatar();
		$image = $avatar->get($user, $size);

		\OC_Response::disableCaching();
		\OC_Response::setLastModifiedHeader(time());
		if ($image instanceof \OC_Image) {
			\OC_Response::setETagHeader(crc32($image->data()));
			$image->show();
		} else {
			\OC_JSON::success(array('user' => $user, 'size' => $size));
		}
	}

	public static function postAvatar($args) {
		$user = \OC_User::getUser();

		if (isset($_POST['path'])) {
			$path = stripslashes($_POST['path']);
			$view = new \OC\Files\View('/'.$user.'/files');
			$newAvatar = $view->file_get_contents($path);
		} elseif (!empty($_FILES)) {
			$files = $_FILES['files'];
			if (
				$files['error'][0] === 0 &&
				is_uploaded_file($files['tmp_name'][0]) &&
				!\OC\Files\Filesystem::isFileBlacklisted($files['tmp_name'][0])
			) {
				$newAvatar = file_get_contents($files['tmp_name'][0]);
				unlink($files['tmp_name'][0]);
			}
		} else {
			$l = new \OC_L10n('core');
			\OC_JSON::error(array("data" => array("message" => $l->t("No image or file provided")) ));
			return;
		}

		try {
			$avatar = new \OC_Avatar();
			$avatar->set($user, $newAvatar);
			\OC_JSON::success();
		} catch (\OC\NotSquareException $e) {
			$image = new \OC_Image($newAvatar);

			if ($image->valid()) {
				\OC_Cache::set('tmpavatar', $image->data(), 7200);
				\OC_JSON::error(array("data" => array("message" => "notsquare") ));
			} else {
				$l = new \OC_L10n('core');
				$type = substr($image->mimeType(), -3);
				if ($type === 'peg') {
					$type = 'jpg';
				}
				if ($type !== 'jpg' && $type !== 'png') {
					\OC_JSON::error(array("data" => array("message" => $l->t("Unknown filetype")) ));
				}

				if (!$image->valid()) {
					\OC_JSON::error(array("data" => array("message" => $l->t("Invalid image")) ));
				}
			}
		} catch (\Exception $e) {
			\OC_JSON::error(array("data" => array("message" => $e->getMessage()) ));
		}
	}

	public static function deleteAvatar($args) {
		$user = \OC_User::getUser();

		try {
			$avatar = new \OC_Avatar();
			$avatar->remove($user);
			\OC_JSON::success();
		} catch (\Exception $e) {
			\OC_JSON::error(array("data" => array("message" => $e->getMessage()) ));
		}
	}

	public static function getTmpAvatar($args) {
		$tmpavatar = \OC_Cache::get('tmpavatar');
		if (is_null($tmpavatar)) {
			$l = new \OC_L10n('core');
			\OC_JSON::error(array("data" => array("message" => $l->t("No temporary profile picture available, try again")) ));
			return;
		}

		$image = new \OC_Image($tmpavatar);
		\OC_Response::disableCaching();
		\OC_Response::setLastModifiedHeader(time());
		\OC_Response::setETagHeader(crc32($image->data()));
		$image->show();
	}

	public static function postCroppedAvatar($args) {
		$user = \OC_User::getUser();
		if (isset($_POST['crop'])) {
			$crop = $_POST['crop'];
		} else {
			$l = new \OC_L10n('core');
			\OC_JSON::error(array("data" => array("message" => $l->t("No crop data provided")) ));
			return;
		}

		$tmpavatar = \OC_Cache::get('tmpavatar');
		if (is_null($tmpavatar)) {
			$l = new \OC_L10n('core');
			\OC_JSON::error(array("data" => array("message" => $l->t("No temporary profile picture available, try again")) ));
			return;
		}

		$image = new \OC_Image($tmpavatar);
		$image->crop($crop['x'], $crop['y'], $crop['w'], $crop['h']);
		try {
			$avatar = new \OC_Avatar();
			$avatar->set($user, $image->data());
			// Clean up
			\OC_Cache::remove('tmpavatar');
			\OC_JSON::success();
		} catch (\Exception $e) {
			\OC_JSON::error(array("data" => array("message" => $e->getMessage()) ));
		}
	}
}

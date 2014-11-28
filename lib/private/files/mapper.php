<?php

namespace OC\Files;

/**
 * class Mapper is responsible to translate logical paths to physical paths and reverse
 */
class Mapper
{
	/** @var string Unchanged root path as has been given to the constructor */
	private $unchangedPhysicalRoot;
	/** @var string Cleaned root path without relative path segments */
	private $resolvePhysicalRoot;

	public function __construct($rootDir) {
		$this->unchangedPhysicalRoot = $rootDir;
		// Resolve ./, ../ and // so we can compare it to the resolved links we get alter on.
		$this->resolvePhysicalRoot = $this->resolveRelativePath($rootDir);
	}

	/**
	 * @param string $logicPath
	 * @param bool $create indicates if the generated physical name shall be stored in the database or not
	 * @return string the physical path
	 */
	public function logicToPhysical($logicPath, $create) {
		$physicalPath = $this->resolveLogicPath($logicPath);
		if ($physicalPath !== null) {
			return $physicalPath;
		}

		return $this->create($logicPath, $create, 0, $logicPath);
	}

	/**
	 * @param string $physicalPath
	 * @return string
	 */
	public function physicalToLogic($physicalPath) {
		$logicPath = $this->resolvePhysicalPath($physicalPath);
		if ($logicPath !== null) {
			return $logicPath;
		}

		$this->insert($physicalPath, $physicalPath);
		return $physicalPath;
	}

	/**
	 * @param string $path
	 * @param bool $isLogicPath indicates if $path is logical or physical
	 * @param boolean $recursive
	 * @return void
	 */
	public function removePath($path, $isLogicPath, $recursive) {
		if ($recursive) {
			$path=$path.'%';
		}

		if ($isLogicPath) {
			\OC_DB::executeAudited('DELETE FROM `*PREFIX*file_map` WHERE `logic_path` LIKE ?', array($path));
		} else {
			\OC_DB::executeAudited('DELETE FROM `*PREFIX*file_map` WHERE `physic_path` LIKE ?', array($path));
		}
	}

	/**
	 * @param string $path1
	 * @param string $path2
	 * @throws \Exception
	 */
	public function copy($path1, $path2)
	{
		$path1 = $this->resolveRelativePath($path1);
		$path2 = $this->resolveRelativePath($path2);
		$physicPath1 = $this->logicToPhysical($path1, true);
		$physicPath2 = $this->logicToPhysical($path2, true);

		$sql = 'SELECT * FROM `*PREFIX*file_map` WHERE `logic_path` LIKE ?';
		$result = \OC_DB::executeAudited($sql, array($path1.'%'));
		$updateQuery = \OC_DB::prepare('UPDATE `*PREFIX*file_map`'
			.' SET `logic_path` = ?'
			.' , `logic_path_hash` = ?'
			.' , `physic_path` = ?'
			.' , `physic_path_hash` = ?'
			.' WHERE `logic_path` = ?');
		while( $row = $result->fetchRow()) {
			$currentLogic = $row['logic_path'];
			$currentPhysic = $row['physic_path'];
			$newLogic = $path2.$this->stripRootFolder($currentLogic, $path1);
			$newPhysic = $physicPath2.$this->stripRootFolder($currentPhysic, $physicPath1);
			if ($path1 !== $currentLogic) {
				try {
					\OC_DB::executeAudited($updateQuery, array($newLogic, md5($newLogic), $newPhysic, md5($newPhysic),
						$currentLogic));
				} catch (\Exception $e) {
					error_log('Mapper::Copy failed '.$currentLogic.' -> '.$newLogic.'\n'.$e);
					throw $e;
				}
			}
		}
	}

	/**
	 * @param string $path
	 * @param string $root
	 * @return false|string
	 */
	public function stripRootFolder($path, $root) {
		if (strpos($path, $root) !== 0) {
			// throw exception ???
			return false;
		}
		if (strlen($path) > strlen($root)) {
			return substr($path, strlen($root));
		}

		return '';
	}

	/**
	 * @param string $logicPath
	 */
	private function resolveLogicPath($logicPath) {
		$logicPath = $this->resolveRelativePath($logicPath);
		$sql = 'SELECT * FROM `*PREFIX*file_map` WHERE `logic_path_hash` = ?';
		$result = \OC_DB::executeAudited($sql, array(md5($logicPath)));
		$result = $result->fetchRow();
		if ($result === false) {
			return null;
		}

		return $result['physic_path'];
	}

	private function resolvePhysicalPath($physicalPath) {
		$physicalPath = $this->resolveRelativePath($physicalPath);
		$sql = \OC_DB::prepare('SELECT * FROM `*PREFIX*file_map` WHERE `physic_path_hash` = ?');
		$result = \OC_DB::executeAudited($sql, array(md5($physicalPath)));
		$result = $result->fetchRow();

		return $result['logic_path'];
	}

	private function resolveRelativePath($path) {
		$explodedPath = explode('/', $path);
		$pathArray = array();
		foreach ($explodedPath as $pathElement) {
			if (empty($pathElement) || ($pathElement == '.')) {
				continue;
			} elseif ($pathElement == '..') {
				if (count($pathArray) == 0) {
					return false;
				}
				array_pop($pathArray);
			} else {
				if (substr($pathElement, -2) === '\\.') {
					$pathElement = substr($pathElement, 0, -2);
				}
				array_push($pathArray, $pathElement);
			}
		}
		if (substr($path, 0, 1) == '/') {
			$path = '/';
		} else {
			$path = '';
		}
		return $path.implode('/', $pathArray);
	}

	/**
	 * @param string $logicPath
	 * @param boolean $store
	 */
	private function create($logicPath, $store, $depth, $path) {
		if ($depth == 5 || $depth == 10 || $depth == 25) {
			var_dump(
				$depth,
				$path,
				$logicPath,
				$this->resolvePhysicalRoot
			);
			$wtf = true;
		}
		$logicPath = $this->resolveRelativePath($logicPath);

		if ($logicPath === $this->resolvePhysicalRoot ||
			$logicPath . '/' === $this->resolvePhysicalRoot ||
			$logicPath . '\\' === $this->resolvePhysicalRoot) {
			// If the path is the physical root, we are done with the recursion
			return $logicPath;
		}

		$resolvedLogicPath = $this->resolveLogicPath($logicPath);
		if ($resolvedLogicPath !== null) {
			// If the path has a mapper entry, we are done with the recursion
			return $resolvedLogicPath;
		}

		// Didn't find the path so we use the parentPath and append the slugified fileName
		$physicalParentPath = $this->create(dirname($logicPath), $store, $depth + 1, $path);
		$logicFileName = basename($logicPath);
		$slugifiedLogicFileName = $this->slugify($logicFileName);

		// Detect duplicate fileNames after they have been slugified
		$index = 0;
		$physicalPath = $physicalParentPath . '/' . $slugifiedLogicFileName;
		while ($this->resolvePhysicalPath($physicalPath) !== null) {
			$physicalPath = $physicalParentPath . '/' . $this->addIndexToFilename($slugifiedLogicFileName, $index++);
		}

		// Insert the new path mapping if requested
		if ($store) {
			$this->insert($logicPath, $physicalPath);
		}

		return $physicalPath;
	}

	private function insert($logicPath, $physicalPath) {
		$sql = 'INSERT INTO `*PREFIX*file_map` (`logic_path`, `physic_path`, `logic_path_hash`, `physic_path_hash`)
				VALUES (?, ?, ?, ?)';
		\OC_DB::executeAudited($sql, array($logicPath, $physicalPath, md5($logicPath), md5($physicalPath)));
	}

	/**
	 * @param string $fileName
	 * @param int $index
	 * @return string
	 */
	private function addIndexToFilename($fileName, $index = 0) {
		if (!$index) {
			return $fileName;
		}

		// if filename contains periods - add index number before last period
		if (preg_match('~\.[^\.]+$~i', $fileName, $extension)) {
			$fileName = substr($fileName, 0, -(strlen($extension[0]))) . '-' . $index . $extension[0];
		} else {
			// if filename doesn't contain periods add index after the last char
			$fileName .= '-' . $index;
		}

		return $fileName;
	}

	/**
	 * Modifies a string to remove all non ASCII characters and spaces.
	 *
	 * @param string $text
	 * @return string
	 */
	private function slugify($text) {
		$originalText = $text;
		// replace non letter or digits or dots by -
		$text = preg_replace('~[^\\pL\d\.]+~u', '-', $text);

		// trim
		$text = trim($text, '-');

		// transliterate
		if (function_exists('iconv')) {
			$text = iconv('utf-8', 'us-ascii//TRANSLIT//IGNORE', $text);
		}

		// lowercase
		$text = strtolower($text);

		// remove unwanted characters
		$text = preg_replace('~[^-\w\.]+~', '', $text);
		
		// trim ending dots (for security reasons and win compatibility)
		$text = preg_replace('~\.+$~', '', $text);

		if (empty($text)) {
			/**
			 * Item slug would be empty. Previously we used uniqid() here.
			 * However this means that the behaviour is not reproducible, so
			 * when uploading files into a "empty" folder, the folders name is
			 * different.
			 *
			 * If there would be a md5() hash collision, the deduplicate check
			 * will spot this and append an index later, so this should not be
			 * a problem.
			 */
			return md5($originalText);
		}

		return $text;
	}
}

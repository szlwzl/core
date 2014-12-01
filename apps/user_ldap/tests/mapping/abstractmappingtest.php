<?php
/**
* Copyright (c) 2014 Arthur Schiwon <blizzz@owncloud.com>
* This file is licensed under the Affero General Public License version 3 or
* later.
* See the COPYING-README file.
*/

namespace OCA\user_ldap\tests\mapping;

abstract class AbstractMappingTest extends \PHPUnit_Framework_TestCase {
	abstract public function getMapper(\OCP\IDBConnection $dbMock);

	/**
	 * kiss test on isColNameValid
	 */
	public function testIsColNameValid() {
		$dbMock = $this->getMock('\OCP\IDBConnection');
		$mapper = $this->getMapper($dbMock);

		$this->assertTrue($mapper->isColNameValid('ldap_dn'));
		$this->assertFalse($mapper->isColNameValid('foobar'));
	}

	/**
	 * returns an array of test entries with dn, name and uuid as keys
	 * @return array
	 */
	protected function getTestData() {
		$data = array(
			array(
				'dn' => 'uid=foobar,dc=example,dc=org',
				'name' => 'Foobar',
				'uuid' => '1111-AAAA-1234-CDEF',
			),
			array(
				'dn' => 'uid=barfoo,dc=example,dc=org',
				'name' => 'Barfoo',
				'uuid' => '2222-BBBB-1234-CDEF',
			),
			array(
				'dn' => 'uid=barabara,dc=example,dc=org',
				'name' => 'BaraBara',
				'uuid' => '3333-CCCC-1234-CDEF',
			)
		);

		return $data;
	}

	/**
	 * calls map() on the given mapper and asserts result for true
	 * @param \OCA\UserLDAP\Mapping\AbstractMapping $mapper
	 * @param array $data
	 */
	protected function mapEntries($mapper, $data) {
		foreach($data as $entry) {
			$done = $mapper->map($entry['dn'], $entry['name'], $entry['uuid']);
			$this->assertTrue($done);
		}
	}

	/**
	 * initalizes environment for a test run and fills given parameters with
	 * test objects. Preparing environment means that all mappings are cleared
	 * first and then filled with test entries.
	 * @param null $dbc by reference, becomes an instance of \OCP\IDBConnection
	 * @param null $mapper by reference, becomes an instance of
	 * \OCA\UserLDAP\Mapping\AbstractMapping
	 * @param null $data by reference, becomes an array of test users or groups
	 */
	private function initTest(&$dbc, &$mapper, &$data) {
		$dbc = \OC::$server->getDatabaseConnection();
		$mapper = $this->getMapper($dbc);
		$data = $this->getTestData();
		// make sure DB is pristine, then fill it with test entries
		$mapper->clear();
		$this->mapEntries($mapper, $data);
	}

	/**
	 * tests map() method with input that should result in not-mapping.
	 * Hint: successful mapping is tested inherently with mapEntries().
	 */
	public function testMap() {
		$dbc = $mapper = $data = null;
		$this->initTest($dbc, $mapper, $data);

		// test that mapping will not happen when it shall not
		$paramKeys = array('', 'dn', 'name', 'uuid');
		foreach($paramKeys as $key) {
			$failEntry = $data[0];
			if(!empty($key)) {
				$failEntry[$key] = 'do-not-get-mapped';
			}
			$isMapped = $mapper->map($failEntry['dn'], $failEntry['name'], $failEntry['uuid']);
			$this->assertFalse($isMapped);
		}
	}

	/**
	 * tests getDNByName(), getNameByDN() and getNameByUUID() for successful
	 * and unsuccessful requests.
	 */
	public function testGetMethods() {
		$dbc = $mapper = $data = null;
		$this->initTest($dbc, $mapper, $data);

		foreach($data as $entry) {
			$fdn = $mapper->getDNByName($entry['name']);
			$this->assertSame($fdn, $entry['dn']);
		}
		$fdn = $mapper->getDNByName('nosuchname');
		$this->assertFalse($fdn);

		foreach($data as $entry) {
			$name = $mapper->getNameByDN($entry['dn']);
			$this->assertSame($name, $entry['name']);
		}
		$name = $mapper->getNameByDN('nosuchdn');
		$this->assertFalse($name);

		foreach($data as $entry) {
			$name = $mapper->getNameByUUID($entry['uuid']);
			$this->assertSame($name, $entry['name']);
		}
		$name = $mapper->getNameByUUID('nosuchuuid');
		$this->assertFalse($name);
	}

	/**
	 * tests getNamesBySearch() for successful and unsuccessful requests.
	 */
	public function testSearch() {
		$dbc = $mapper = $data = null;
		$this->initTest($dbc, $mapper, $data);

		$names = $mapper->getNamesBySearch('%oo%');
		$this->assertTrue(is_array($names));
		$this->assertSame(2, count($names));
		$this->assertTrue(in_array('Foobar', $names));
		$this->assertTrue(in_array('Barfoo', $names));
		$names = $mapper->getNamesBySearch('nada');
		$this->assertTrue(is_array($names));
		$this->assertSame(0, count($names));
	}

	/**
	 * tests setDNbyUUID() for successful and unsuccessful update.
	 */
	public function testSetMethod() {
		$dbc = $mapper = $data = null;
		$this->initTest($dbc, $mapper, $data);

		$newDN = 'uid=modified,dc=example,dc=org';
		$done = $mapper->setDNbyUUID($newDN, $data[0]['uuid']);
		$this->assertTrue($done);
		$fdn = $mapper->getDNByName($data[0]['name']);
		$this->assertSame($fdn, $newDN);

		$newDN = 'uid=notme,dc=example,dc=org';
		$done = $mapper->setDNbyUUID($newDN, 'iamnothere');
		$this->assertFalse($done);
		$name = $mapper->getNameByDN($newDN);
		$this->assertFalse($name);

	}

	/**
	 * tests clear() for successful update.
	 */
	public function testClear() {
		$dbc = $mapper = $data = null;
		$this->initTest($dbc, $mapper, $data);

		$done = $mapper->clear();
		$this->assertTrue($done);
		foreach($data as $entry) {
			$name = $mapper->getNameByUUID($entry['uuid']);
			$this->assertFalse($name);
		}
	}
}

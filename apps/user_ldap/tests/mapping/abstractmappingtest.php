<?php
/**
* Copyright (c) 2014 Arthur Schiwon <blizzz@owncloud.com>
* This file is licensed under the Affero General Public License version 3 or
* later.
* See the COPYING-README file.
*/

namespace OCA\user_ldap\tests\mapping;

use OCA\UserLDAP\Mapping\UserMapping;

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
	 * returns an array of test users with dn, name and uuid as keys
	 * @return array
	 */
	protected function getTestUsers() {
		$users = array(
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

		return $users;
	}

	/**
	 * calls map() on the given mapper and asserts result for true
	 * @param \OCA\UserLDAP\Mapping\AbstractMapping $mapper
	 * @param array $users
	 */
	protected function mapUsers($mapper, $users) {
		foreach($users as $user) {
			$done = $mapper->map($user['dn'], $user['name'], $user['uuid']);
			$this->assertTrue($done);
		}
	}

	/**
	 * initalizes environment for a test run and fills given parameters with
	 * test objects. Preparing environment means that all mappings are cleared
	 * first and then filled with the tests users.
	 * @param null $dbc by reference, becomes an instance of \OCP\IDBConnection
	 * @param null $mapper by reference, becomes an instance of
	 * \OCA\UserLDAP\Mapping\AbstractMapping
	 * @param null $users by reference, becomes an array of test users
	 */
	private function initTest(&$dbc, &$mapper, &$users) {
		$dbc = \OC::$server->getDatabaseConnection();
		$mapper = $this->getMapper($dbc);
		$users = $this->getTestUsers();
		// make sure DB is pristine, then fill it with test users
		$mapper->clear();
		$this->mapUsers($mapper, $users);
	}

	/**
	 * tests map() method with input that should result in not-mapping.
	 * Hint: successful mapping is tested inherently with mapUsers().
	 */
	public function testMap() {
		$dbc = $mapper = $users = null;
		$this->initTest($dbc, $mapper, $users);

		// test that mapping will not happen when it shall not
		$paramKeys = array('', 'dn', 'name', 'uuid');
		foreach($paramKeys as $key) {
			$failUser = $users[0];
			if(!empty($key)) {
				$failUser[$key] = 'do-not-get-mapped';
			}
			$isMapped = $mapper->map($failUser['dn'], $failUser['name'], $failUser['uuid']);
			$this->assertFalse($isMapped);
		}
	}

	/**
	 * tests getDNByName(), getNameByDN() and getNameByUUID() for successful
	 * and unsuccessful requests.
	 */
	public function testGetMethods() {
		$dbc = $mapper = $users = null;
		$this->initTest($dbc, $mapper, $users);

		foreach($users as $user) {
			$fdn = $mapper->getDNByName($user['name']);
			$this->assertSame($fdn, $user['dn']);
		}
		$fdn = $mapper->getDNByName('nosuchname');
		$this->assertFalse($fdn);

		foreach($users as $user) {
			$name = $mapper->getNameByDN($user['dn']);
			$this->assertSame($name, $user['name']);
		}
		$name = $mapper->getNameByDN('nosuchdn');
		$this->assertFalse($name);

		foreach($users as $user) {
			$name = $mapper->getNameByUUID($user['uuid']);
			$this->assertSame($name, $user['name']);
		}
		$name = $mapper->getNameByUUID('nosuchuuid');
		$this->assertFalse($name);
	}

	/**
	 * tests getNamesBySearch() for successful and unsuccessful requests.
	 */
	public function testSearch() {
		$dbc = $mapper = $users = null;
		$this->initTest($dbc, $mapper, $users);

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
		$dbc = $mapper = $users = null;
		$this->initTest($dbc, $mapper, $users);

		$newDN = 'uid=modified,dc=example,dc=org';
		$done = $mapper->setDNbyUUID($newDN, $users[0]['uuid']);
		$this->assertTrue($done);
		$fdn = $mapper->getDNByName($users[0]['name']);
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
		$dbc = $mapper = $users = null;
		$this->initTest($dbc, $mapper, $users);

		$done = $mapper->clear();
		$this->assertTrue($done);
		foreach($users as $user) {
			$name = $mapper->getNameByUUID($user['uuid']);
			$this->assertFalse($name);
		}
	}
}

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

	protected function getDBMock() {
		return $this->getMock('\OCP\IDBConnection');
	}

	/**
	 * returns a statement mock
	 * @param bool $success return value of execute()
	 * @param string $vIn the input parameter for execute()
	 * @param string $vOut optional, the result returned by fetchColumn
	 * @return \Doctrine\DBAL\Statement
	 */
	protected function getStatementMock($success, $vIn, $vOut = '') {
		$stmtMock = $this->getMockBuilder('\Doctrine\DBAL\Statement')
			->disableOriginalConstructor()
			->getMock();

		$stmtMock->expects($this->once())
			->method('execute')
			->with(array($vIn))
			->will($this->returnValue($success));

		if($success === true) {
			$stmtMock->expects($this->once())
				->method('fetchColumn')
				->will($this->returnValue($vOut));
		} else {
			$stmtMock->expects($this->never())
				->method('fetchColumn');
		}

		return $stmtMock;
	}

	protected function statementMockFetchRow(&$stmtMock, $return) {
		$stmtMock->expects($this->once())
			->method('fetchRow')
			->will($this->onConsecutiveCalls($return));

	}

	protected function xByYTestSuccess($method, $input, $expected) {
		$stmtMock = $this->getStatementMock(true, $input, $expected);

		$dbMock = $this->getDBmock();
		$dbMock->expects($this->once())
			->method('prepare')
			->will($this->returnValue($stmtMock));

		$mapper = $this->getMapper($dbMock);

		$result = $mapper->$method($input);

		$this->assertSame($result, $expected);
	}

	protected function xByYTestNoSuccess($method, $input) {
		$stmtMock = $this->getStatementMock(false, $input);

		$dbMock = $this->getDBmock();
		$dbMock->expects($this->once())
			->method('prepare')
			->will($this->returnValue($stmtMock));

		$mapper = $this->getMapper($dbMock);

		$result = $mapper->$method($input);

		$this->assertFalse($result);
	}

	public function testGetDNByNameSuccess() {
		$this->xByYTestSuccess('getDNByName', 'alice', 'uid=alice,dc=example,dc=org');
	}

	public function testGetDNByNameNoSuccess() {
		$this->xByYTestNoSuccess('getDNByName', 'alice');
	}

	public function testGetNameByDNSuccess() {
		$this->xByYTestSuccess('getNameByDN', 'uid=alice,dc=example,dc=org', 'alice');
	}

	public function testGetNameByDNNoSuccess() {
		$this->xByYTestNoSuccess('getNameByDN', 'uid=alice,dc=example,dc=org');
	}

	public function testGetNameByUUIDSuccess() {
		$this->xByYTestSuccess('getNameByUUID', '123-abc-4d5e6f-6666', 'alice');
	}

	public function testGetNameByUUIDNoSuccess() {
		$this->xByYTestNoSuccess('getNameByUUID', '123-abc-4d5e6f-6666');
	}

	// public function testGetNamesBySearchEmpty() {
	// 	$stmtMock = $this->getStatementMock(false, $input);
	//
	// 	$dbMock = $this->getDBmock();
	// 	$dbMock->expects($this->once())
	// 		->method('prepare')
	// 		->will($this->returnValue($stmtMock));
	//
	// 	$mapper = $this->getMapper($dbMock);
	// }

	public function testIsColNameValid() {
		$dbMock = $this->getDBmock();
		$mapper = $this->getMapper($dbMock);

		$this->assertTrue($mapper->isColNameValid('ldap_dn'));
		$this->assertFalse($mapper->isColNameValid('foobar'));
	}

	public function testWithDatabase() {
		$dbc = \OC::$server->getDatabaseConnection();
		$mapper = $this->getMapper($dbc);

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

		$mapper->clear(); // for a pristine DB
		//fill with users
		foreach($users as $user) {
			$done = $mapper->map($user['dn'], $user['name'], $user['uuid']);
			$this->assertTrue((bool)$done);
		}
		$done = $mapper->map($users[0]['dn'], $users[0]['name'], $users[0]['uuid']);
		$this->assertFalse((bool)$done);
		$done = $mapper->map($users[0]['dn'], 'donotappear1', $users[0]['uuid']);
		$this->assertFalse((bool)$done);
		$done = $mapper->map('donotappear', $users[0]['name'], $users[0]['uuid']);
		$this->assertFalse((bool)$done);
		$done = $mapper->map($users[0]['dn'], $users[0]['name'], 'donotappear');
		$this->assertFalse((bool)$done);
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

		$names = $mapper->getNamesBySearch('%oo%');
		$this->assertTrue(is_array($names));
		$this->assertSame(2, count($names));
		$this->assertTrue(in_array('Foobar', $names));
		$this->assertTrue(in_array('Barfoo', $names));
		$names = $mapper->getNamesBySearch('nada');
		$this->assertTrue(is_array($names));
		$this->assertSame(0, count($names));

		$newDN = 'uid=modified,dc=example,dc=org';
		$done = $mapper->setDNbyUUID($newDN, $users[0]['uuid']);
		$this->assertTrue($done);
		$fdn = $mapper->getDNByName($users[0]['name']);
		$this->assertSame($fdn, $newDN);

		$done = $mapper->clear();
		$this->assertTrue($done);
		foreach($users as $user) {
			$name = $mapper->getNameByUUID($user['uuid']);
			$this->assertFalse($name);
		}
	}

	//TODO: test with real DB: map, get Methods, Clean
	//TODO: test setDNbyUUID
	//TODO: test getNamesBySearch
	//TODO: test map
	//TODO: test clear

}

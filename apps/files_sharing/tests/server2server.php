<?php
/**
 * ownCloud - test server-to-server OCS API
 *
 * @copyright (c) ownCloud, Inc.
 *
 * @author Bjoern Schiessle <schiessle@owncloud.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

use OCA\Files_sharing\Tests\TestCase;

/**
 * Class Test_Files_Sharing_Api
 */
class Test_Files_Sharing_S2S_OCS_API extends TestCase {

	const TEST_FOLDER_NAME = '/folder_share_api_test';

	private $s2s;

	protected function setUp() {
		parent::setUp();

		$this->s2s = new \OCA\Files_Sharing\API\Server2Server();
	}

	protected function tearDown() {
		parent::tearDown();
	}

	/**
	 * @medium
	 */
	function testCreateShare() {
		// simulate a post request
		$_POST['remote'] = 'localhost';
		$_POST['token'] = 'token';
		$_POST['name'] = 'name';
		$_POST['owner'] = 'owner';
		$_POST['shareWith'] = 'admin';
		$_POST['remote_id'] = 1;

		$result = $this->s2s->createShare(null);
		$this->assertTrue($result->succeeded());

		$query = \OCP\DB::prepare('SELECT * FROM `*PREFIX*share_external` WHERE `remote_id` = ?');
		$result = $query->execute(array('1'));
		$data = $result->fetchRow();

		$this->assertSame('localhost', $data['remote']);
		$this->assertSame('token', $data['share_token']);
		$this->assertSame('/name', $data['name']);
		$this->assertSame('owner', $data['owner']);
		$this->assertSame('admin', $data['user']);
		$this->assertSame('1', $data['remote_id']);
		$this->assertSame('0', $data['accepted']);
	}


	function testDeclineShare() {
		$dummy = \OCP\DB::prepare('
			INSERT INTO `*PREFIX*share`
			(`share_type`, `uid_owner`, `item_type`, `item_source`, `item_target`, `file_source`, `file_target`, `permissions`, `stime`, `token`)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
			');
		$dummy->execute(array(\OCP\Share::SHARE_TYPE_REMOTE, 'admin', 'file', '1', '/1', '1', '/test.txt', '1', time(), 'token'));

		$verify = \OCP\DB::prepare('SELECT * FROM `*PREFIX*share`');
		$result = $verify->execute();
		$data = $result->fetchAll();
		$this->assertSame(1, count($data));

		$_POST['token'] = 'token';
		$this->s2s->declineShare(array('id' => '1'));

		$verify = \OCP\DB::prepare('SELECT * FROM `*PREFIX*share`');
		$result = $verify->execute();
		$data = $result->fetchAll();
		$this->assertEmpty($data);

	}
}

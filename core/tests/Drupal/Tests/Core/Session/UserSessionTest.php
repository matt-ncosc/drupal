<?php

declare(strict_types=1);

namespace Drupal\Tests\Core\Session;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Cache\MemoryCache\MemoryCache;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\PermissionChecker;
use Drupal\Core\Session\UserSession;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;

/**
 * @coversDefaultClass \Drupal\Core\Session\UserSession
 * @group Session
 */
class UserSessionTest extends UnitTestCase {

  /**
   * The user sessions used in the test.
   *
   * @var \Drupal\Core\Session\AccountInterface[]
   */
  protected $users = [];

  /**
   * Provides test data for getHasPermission().
   *
   * @return array
   */
  public static function providerTestHasPermission() {
    $data = [];
    $data[] = ['example permission', ['user_one', 'user_two'], ['user_last']];
    $data[] = ['another example permission', ['user_two'], ['user_one', 'user_last']];
    $data[] = ['final example permission', [], ['user_one', 'user_two', 'user_last']];

    return $data;
  }

  /**
   * Setups a user session for the test.
   *
   * @param array $rids
   *   The rids of the user.
   * @param bool $authenticated
   *   TRUE if it is an authenticated user.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The created user session.
   */
  protected function createUserSession(array $rids = [], $authenticated = FALSE) {
    array_unshift($rids, $authenticated ? RoleInterface::AUTHENTICATED_ID : RoleInterface::ANONYMOUS_ID);
    return new UserSession(['roles' => $rids]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $roles = [];
    $roles['role_one'] = $this->getMockBuilder('Drupal\user\Entity\Role')
      ->disableOriginalConstructor()
      ->onlyMethods(['hasPermission'])
      ->getMock();
    $roles['role_one']->expects($this->any())
      ->method('hasPermission')
      ->willReturnMap([
        ['example permission', TRUE],
        ['another example permission', FALSE],
        ['last example permission', FALSE],
      ]);

    $roles['role_two'] = $this->getMockBuilder('Drupal\user\Entity\Role')
      ->disableOriginalConstructor()
      ->onlyMethods(['hasPermission'])
      ->getMock();
    $roles['role_two']->expects($this->any())
      ->method('hasPermission')
      ->willReturnMap([
        ['example permission', TRUE],
        ['another example permission', TRUE],
        ['last example permission', FALSE],
      ]);

    $roles['anonymous'] = $this->getMockBuilder('Drupal\user\Entity\Role')
      ->disableOriginalConstructor()
      ->onlyMethods(['hasPermission'])
      ->getMock();
    $roles['anonymous']->expects($this->any())
      ->method('hasPermission')
      ->willReturnMap([
        ['example permission', FALSE],
        ['another example permission', FALSE],
        ['last example permission', FALSE],
      ]);

    $role_storage = $this->getMockBuilder('Drupal\user\RoleStorage')
      ->setConstructorArgs(['role', new MemoryCache(new Time())])
      ->disableOriginalConstructor()
      ->onlyMethods(['loadMultiple'])
      ->getMock();
    $role_storage->expects($this->any())
      ->method('loadMultiple')
      ->willReturnMap([
        [[], []],
        [NULL, $roles],
        [['anonymous'], [$roles['anonymous']]],
        [['anonymous', 'role_one'], [$roles['role_one']]],
        [['anonymous', 'role_two'], [$roles['role_two']]],
        [
          ['anonymous', 'role_one', 'role_two'],
          [$roles['role_one'], $roles['role_two']],
        ],
      ]);

    $entity_type_manager = $this->createMock('Drupal\Core\Entity\EntityTypeManagerInterface');
    $entity_type_manager->expects($this->any())
      ->method('getStorage')
      ->with($this->equalTo('user_role'))
      ->willReturn($role_storage);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('permission_checker', new PermissionChecker($entity_type_manager));
    \Drupal::setContainer($container);

    $this->users['user_one'] = $this->createUserSession(['role_one']);
    $this->users['user_two'] = $this->createUserSession(['role_one', 'role_two']);
    $this->users['user_three'] = $this->createUserSession(['role_two'], TRUE);
    $this->users['user_last'] = $this->createUserSession();
  }

  /**
   * Tests the has permission method.
   *
   * @param string $permission
   *   The permission to check.
   * @param \Drupal\Core\Session\AccountInterface[] $sessions_with_access
   *   The users with access.
   * @param \Drupal\Core\Session\AccountInterface[] $sessions_without_access
   *   The users without access.
   *
   * @dataProvider providerTestHasPermission
   *
   * @see \Drupal\Core\Session\UserSession::hasPermission()
   */
  public function testHasPermission($permission, array $sessions_with_access, array $sessions_without_access) {
    foreach ($sessions_with_access as $name) {
      $this->assertTrue($this->users[$name]->hasPermission($permission));
    }
    foreach ($sessions_without_access as $name) {
      $this->assertFalse($this->users[$name]->hasPermission($permission));
    }
  }

  /**
   * Tests the method getRoles exclude or include locked roles based in param.
   *
   * @covers ::getRoles
   * @todo Move roles constants to a class/interface
   */
  public function testUserGetRoles() {
    $this->assertEquals([RoleInterface::AUTHENTICATED_ID, 'role_two'], $this->users['user_three']->getRoles());
    $this->assertEquals(['role_two'], $this->users['user_three']->getRoles(TRUE));
  }

  /**
   * Tests the hasRole method.
   *
   * @covers ::hasRole
   */
  public function testHasRole() {
    $this->assertTrue($this->users['user_one']->hasRole('role_one'));
    $this->assertFalse($this->users['user_two']->hasRole('no role'));
    $this->assertTrue($this->users['user_three']->hasRole(RoleInterface::AUTHENTICATED_ID));
    $this->assertFalse($this->users['user_three']->hasRole(RoleInterface::ANONYMOUS_ID));
    $this->assertTrue($this->users['user_last']->hasRole(RoleInterface::ANONYMOUS_ID));
  }

  /**
   * Tests deprecation when permission is not a string.
   *
   * @covers ::hasPermission
   * @group legacy
   */
  public function testHasPermissionLegacy() {
    $this->expectDeprecation('Calling Drupal\Core\Session\UserSession::hasPermission() with a $permission parameter of type other than string is deprecated in drupal:10.3.0 and will cause an error in drupal:11.0.0. See https://www.drupal.org/node/3411485');
    $this->assertFalse((new UserSession())->hasPermission(NULL));
    $this->expectDeprecation('Calling Drupal\user\Entity\User::hasPermission() with a $permission parameter of type other than string is deprecated in drupal:10.3.0 and will cause an error in drupal:11.0.0. See https://www.drupal.org/node/3411485');
    $reflection = new \ReflectionClass(User::class);
    $user = $reflection->newInstanceWithoutConstructor();
    $this->assertFalse($user->hasPermission(NULL));
  }

}

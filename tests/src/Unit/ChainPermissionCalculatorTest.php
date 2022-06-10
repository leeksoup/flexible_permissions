<?php

namespace Drupal\Tests\flexible_permissions\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\flexible_permissions\CalculatedPermissions;
use Drupal\flexible_permissions\CalculatedPermissionsItem;
use Drupal\flexible_permissions\CalculatedPermissionsScopeException;
use Drupal\flexible_permissions\ChainPermissionCalculator;
use Drupal\flexible_permissions\PermissionCalculatorBase;
use Drupal\flexible_permissions\RefinableCalculatedPermissions;
use Drupal\Tests\UnitTestCase;
use Drupal\variationcache\Cache\VariationCacheInterface;
use Prophecy\Argument;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests the CalculatedPermissions value object.
 *
 * @coversDefaultClass \Drupal\flexible_permissions\ChainPermissionCalculator
 * @group flexible_permissions
 *
 * @todo Test other methods, caching and account switcher.
 */
class ChainPermissionCalculatorTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $cache_context_manager = $this->prophesize(CacheContextsManager::class);
    $cache_context_manager->assertValidTokens(Argument::any())->willReturn(TRUE);

    $container = $this->prophesize(ContainerInterface::class);
    $container->get('cache_contexts_manager')->willReturn($cache_context_manager->reveal());
    \Drupal::setContainer($container->reveal());
  }

  /**
   * Tests that calculators are properly processed.
   *
   * @covers ::calculatePermissions
   */
  public function testCalculatePermissions() {
    $account = $this->prophesize(AccountInterface::class)->reveal();
    $calculator = new BarScopeCalculator();

    $chain_calculator = $this->setUpChainCalculator();
    $chain_calculator->addCalculator($calculator);

    $calculator_permissions = $calculator->calculatePermissions($account, 'bar');
    $calculator_permissions->addCacheTags(['flexible_permissions']);
    $calculated_permissions = $chain_calculator->calculatePermissions($account, 'bar');
    $this->assertEquals(new CalculatedPermissions($calculator_permissions), $calculated_permissions);
  }

  /**
   * Tests that calculators which do nothing are properly processed.
   *
   * @covers ::calculatePermissions
   */
  public function testEmptyCalculator() {
    $account = $this->prophesize(AccountInterface::class)->reveal();
    $calculator = new EmptyCalculator();

    $chain_calculator = $this->setUpChainCalculator();
    $chain_calculator->addCalculator($calculator);

    $calculator_permissions = $calculator->calculatePermissions($account, 'anything');
    $calculator_permissions->addCacheTags(['flexible_permissions']);
    $calculated_permissions = $chain_calculator->calculatePermissions($account, 'anything');
    $this->assertEquals(new CalculatedPermissions($calculator_permissions), $calculated_permissions);
  }

  /**
   * Tests that everything works if no calculators are present.
   *
   * @covers ::calculatePermissions
   */
  public function testNoCalculators() {
    $account = $this->prophesize(AccountInterface::class)->reveal();
    $chain_calculator = $this->setUpChainCalculator();

    $no_permissions = new RefinableCalculatedPermissions();
    $no_permissions->addCacheTags(['flexible_permissions']);
    $calculated_permissions = $chain_calculator->calculatePermissions($account, 'anything');
    $this->assertEquals(new CalculatedPermissions($no_permissions), $calculated_permissions);
  }

  /**
   * Tests the wrong scope exception.
   *
   * @covers ::calculatePermissions
   */
  public function testWrongScopeException() {
    $chain_calculator = $this->setUpChainCalculator();
    $chain_calculator->addCalculator(new FooScopeCalculator());

    $this->expectException(CalculatedPermissionsScopeException::class);
    $this->expectExceptionMessage(sprintf('The calculator "%s" returned permissions for scopes other than "%s".', FooScopeCalculator::class, 'bar'));
    $chain_calculator->calculatePermissions($this->prophesize(AccountInterface::class)->reveal(), 'bar');
  }

  /**
   * Tests the multiple scopes exception.
   *
   * @covers ::calculatePermissions
   */
  public function testMultipleScopeException() {
    $chain_calculator = $this->setUpChainCalculator();
    $chain_calculator->addCalculator(new FooScopeCalculator());
    $chain_calculator->addCalculator(new BarScopeCalculator());

    $this->expectException(CalculatedPermissionsScopeException::class);
    $this->expectExceptionMessage(sprintf('The calculator "%s" returned permissions for scopes other than "%s".', FooScopeCalculator::class, 'bar'));
    $chain_calculator->calculatePermissions($this->prophesize(AccountInterface::class)->reveal(), 'bar');
  }

  /**
   * Sets up the chain calculator.
   *
   * @return \Drupal\flexible_permissions\ChainPermissionCalculatorInterface
   */
  protected function setUpChainCalculator() {
    $variation_cache = $this->prophesize(VariationCacheInterface::class);
    $variation_cache->get(Argument::cetera())->willReturn(FALSE);
    $variation_cache->set(Argument::cetera())->willReturn(NULL);
    $variation_cache_static = $this->prophesize(VariationCacheInterface::class);
    $variation_cache_static->get(Argument::cetera())->willReturn(FALSE);
    $variation_cache_static->set(Argument::cetera())->willReturn(NULL);
    $cache_static = $this->prophesize(CacheBackendInterface::class);
    $cache_static->get(Argument::cetera())->willReturn(FALSE);
    $cache_static->set(Argument::cetera())->willReturn(NULL);
    $account_switcher = $this->prophesize(AccountSwitcherInterface::class);

    return new ChainPermissionCalculator(
      $variation_cache->reveal(),
      $variation_cache_static->reveal(),
      $cache_static->reveal(),
      $account_switcher->reveal()
    );
  }

}

class FooScopeCalculator extends PermissionCalculatorBase {

  public function calculatePermissions(AccountInterface $account, $scope) {
    $calculated_permissions = parent::calculatePermissions($account, $scope);
    return $calculated_permissions->addItem(new CalculatedPermissionsItem('foo', 1, [], TRUE));
  }

  public function getPersistentCacheContexts($scope) {
    return ['foo'];
  }

}

class BarScopeCalculator extends PermissionCalculatorBase {

  public function calculatePermissions(AccountInterface $account, $scope) {
    $calculated_permissions = parent::calculatePermissions($account, $scope);
    return $calculated_permissions->addItem(new CalculatedPermissionsItem('bar', 1, [], TRUE));
  }

  public function getPersistentCacheContexts($scope) {
    return ['bar'];
  }

}

class EmptyCalculator extends PermissionCalculatorBase {

}

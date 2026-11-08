<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Functional;

use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The aim_personal recipe, applied to a fresh site.
 *
 * A functional test because a recipe installs modules in config-syncing mode,
 * so hook_install() and config/install behave as they do nowhere else.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimPersonalRecipeTest extends BrowserTestBase {

  use RecipeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'minimal';

  /**
   * {@inheritdoc}
   *
   * The provider's schema for this object is a plain mapping with no _core,
   * which the installer adds. Unrelated to this recipe, and only the strict
   * checker minds.
   */
  protected static $configSchemaCheckerExclusions = [
    'ai_vdb_provider_mariadb.settings',
  ];

  /**
   * Tests what the recipe makes of a fresh site.
   */
  public function testApply(): void {
    $path = $this->container->get('extension.list.module')->getPath('aim') . '/recipes/aim_personal';
    $this->applyRecipe($path, options: ['--input=aim_personal.owner_name=Sam']);

    // The purpose statement reaches the setting a client reads on connecting.
    $instructions = $this->config('mcp_server.settings')->get('server_instructions');
    $this->assertStringContainsString('personal memory of Sam', $instructions);

    // Only the owner's two scopes are on offer, and models read the guide.
    $this->assertSame(['user', 'case'], $this->container->get('aim_tool.scope_guide')->scopeIds());
    $guide = $this->container->get('aim_tool.scope_guide')->guide();
    $this->assertStringContainsString('about the owner of this memory', $guide);
    $this->assertStringNotContainsString('- site:', $guide);

    // Nobody can make their own account on a site on the public internet.
    $this->assertSame('admin_only', $this->config('user.settings')->get('register'));

    // Each tool asks for its OAuth scope, which aim_tool_oauth_install() can't
    // set in a recipe.
    $expected = [
      'aim_remember' => ['aim:remember'],
      'aim_recall' => ['aim:recall'],
      'aim_brief' => ['aim:recall'],
    ];
    $storage = $this->container->get('entity_type.manager')->getStorage('mcp_tool_config');
    foreach ($expected as $id => $scopes) {
      $tool = $storage->load($id);
      $this->assertNotNull($tool, $id);
      $this->assertSame($scopes, $tool->getThirdPartySetting('mcp_server_oauth', 'scopes'), $id);
      $this->assertSame('required', $tool->getThirdPartySetting('mcp_server_oauth', 'authentication_mode'), $id);
    }

    // Discovery names this request's host, not the one that ran the recipe.
    $this->assertSame('', $this->config('simple_oauth_server_metadata.settings')->get('registration_endpoint'));

    // A caller with no token is challenged, not served.
    $response = $this->getHttpClient()->post($this->buildUrl('/mcp'), [
      'http_errors' => FALSE,
      'headers' => ['Content-Type' => 'application/json'],
      'body' => '{"jsonrpc":"2.0","id":1,"method":"ping"}',
    ]);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertStringContainsString('resource_metadata=', $response->getHeaderLine('WWW-Authenticate'));
  }

}

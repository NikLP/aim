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
   * checker minds. The index's tracker, ai_search_tracker, has no schema in
   * ai_search, so every save of the index fails the checker the same way.
   */
  protected static $configSchemaCheckerExclusions = [
    'ai_vdb_provider_mariadb.settings',
    'search_api.index.aim_vector_index',
  ];

  /**
   * {@inheritdoc}
   *
   * The AI module's echo provider, as the site's default models: the recipe
   * points the vector server at them and stops without an embeddings one.
   */
  protected static $modules = ['ai_test'];

  /**
   * Tests what the recipe makes of a fresh site.
   */
  public function testApply(): void {
    $this->config('ai.settings')
      ->set('default_providers.embeddings', ['provider_id' => 'echoai', 'model_id' => 'gpt-test'])
      ->set('default_providers.chat', ['provider_id' => 'echoai', 'model_id' => 'gpt-test'])
      ->save();

    $path = $this->container->get('extension.list.module')->getPath('aim') . '/recipes/aim_personal';
    $this->applyRecipe($path, options: ['--input=aim_personal.owner_name=Sam']);

    // The vector server names the site's models, at the model's own size.
    $backend = $this->config('search_api.server.aim_vector')->get('backend_config');
    $this->assertSame('echoai__gpt-test', $backend['embeddings_engine']);
    $this->assertSame(1, $backend['embeddings_engine_configuration']['dimensions']);
    $this->assertSame('echoai__gpt-test', $backend['chat_model']);

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

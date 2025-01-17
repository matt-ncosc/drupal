<?php

namespace Drupal\Core\Extension;

use Drupal\Component\Graph\Graph;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\Exception\UnknownExtensionException;

/**
 * Class that manages modules in a Drupal installation.
 */
class ModuleHandler implements ModuleHandlerInterface {

  /**
   * List of loaded files.
   *
   * @var array
   *   An associative array whose keys are file paths of loaded files, relative
   *   to the application's root directory.
   */
  protected $loadedFiles;

  /**
   * List of installed modules.
   *
   * @var \Drupal\Core\Extension\Extension[]
   */
  protected $moduleList;

  /**
   * Boolean indicating whether modules have been loaded.
   *
   * @var bool
   */
  protected $loaded = FALSE;

  /**
   * List of hook implementations keyed by hook name.
   *
   * @var array
   */
  protected $implementations;

  /**
   * List of hooks where the implementations have been "verified".
   *
   * @var true[]
   *   Associative array where keys are hook names.
   */
  protected $verified;

  /**
   * Information returned by hook_hook_info() implementations.
   *
   * @var array
   */
  protected $hookInfo;

  /**
   * Cache backend for storing module hook implementation information.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * Whether the cache needs to be written.
   *
   * @var bool
   */
  protected $cacheNeedsWriting = FALSE;

  /**
   * List of alter hook implementations keyed by hook name(s).
   *
   * @var array
   */
  protected $alterFunctions;

  /**
   * The app root.
   *
   * @var string
   */
  protected $root;

  /**
   * A list of module include file keys.
   *
   * @var array
   */
  protected $includeFileKeys = [];

  /**
   * Constructs a ModuleHandler object.
   *
   * @param string $root
   *   The app root.
   * @param array $module_list
   *   An associative array whose keys are the names of installed modules and
   *   whose values are Extension class parameters. This is normally the
   *   %container.modules% parameter being set up by DrupalKernel.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend for storing module hook implementation information.
   *
   * @see \Drupal\Core\DrupalKernel
   * @see \Drupal\Core\CoreServiceProvider
   */
  public function __construct($root, array $module_list, CacheBackendInterface $cache_backend) {
    $this->root = $root;
    $this->moduleList = [];
    foreach ($module_list as $name => $module) {
      $this->moduleList[$name] = new Extension($this->root, $module['type'], $module['pathname'], $module['filename']);
    }
    $this->cacheBackend = $cache_backend;
  }

  /**
   * {@inheritdoc}
   */
  public function load($name) {
    if (isset($this->loadedFiles[$name])) {
      return TRUE;
    }

    if (isset($this->moduleList[$name])) {
      $this->moduleList[$name]->load();
      $this->loadedFiles[$name] = TRUE;
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function loadAll() {
    if (!$this->loaded) {
      foreach ($this->moduleList as $name => $module) {
        $this->load($name);
      }
      $this->loaded = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function reload() {
    $this->loaded = FALSE;
    $this->loadAll();
  }

  /**
   * {@inheritdoc}
   */
  public function isLoaded() {
    return $this->loaded;
  }

  /**
   * {@inheritdoc}
   */
  public function getModuleList() {
    return $this->moduleList;
  }

  /**
   * {@inheritdoc}
   */
  public function getModule($name) {
    if (isset($this->moduleList[$name])) {
      return $this->moduleList[$name];
    }
    throw new UnknownExtensionException(sprintf('The module %s does not exist.', $name));
  }

  /**
   * {@inheritdoc}
   */
  public function setModuleList(array $module_list = []) {
    $this->moduleList = $module_list;
    // Reset the implementations, so a new call triggers a reloading of the
    // available hooks.
    $this->resetImplementations();
  }

  /**
   * {@inheritdoc}
   */
  public function addModule($name, $path) {
    $this->add('module', $name, $path);
  }

  /**
   * {@inheritdoc}
   */
  public function addProfile($name, $path) {
    $this->add('profile', $name, $path);
  }

  /**
   * Adds a module or profile to the list of currently active modules.
   *
   * @param string $type
   *   The extension type; either 'module' or 'profile'.
   * @param string $name
   *   The module name; e.g., 'node'.
   * @param string $path
   *   The module path; e.g., 'core/modules/node'.
   */
  protected function add($type, $name, $path) {
    $pathname = "$path/$name.info.yml";
    $filename = file_exists($this->root . "/$path/$name.$type") ? "$name.$type" : NULL;
    $this->moduleList[$name] = new Extension($this->root, $type, $pathname, $filename);
    $this->resetImplementations();
  }

  /**
   * {@inheritdoc}
   */
  public function buildModuleDependencies(array $modules) {
    foreach ($modules as $module) {
      $graph[$module->getName()]['edges'] = [];
      if (isset($module->info['dependencies']) && is_array($module->info['dependencies'])) {
        foreach ($module->info['dependencies'] as $dependency) {
          $dependency_data = Dependency::createFromString($dependency);
          $graph[$module->getName()]['edges'][$dependency_data->getName()] = $dependency_data;
        }
      }
    }
    $graph_object = new Graph($graph ?? []);
    $graph = $graph_object->searchAndSort();
    foreach ($graph as $module_name => $data) {
      $modules[$module_name]->required_by = $data['reverse_paths'] ?? [];
      $modules[$module_name]->requires = $data['paths'] ?? [];
      $modules[$module_name]->sort = $data['weight'];
    }
    return $modules;
  }

  /**
   * {@inheritdoc}
   */
  public function moduleExists($module) {
    return isset($this->moduleList[$module]);
  }

  /**
   * {@inheritdoc}
   */
  public function loadAllIncludes($type, $name = NULL) {
    foreach ($this->moduleList as $module => $filename) {
      $this->loadInclude($module, $type, $name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadInclude($module, $type, $name = NULL) {
    if ($type == 'install') {
      // Make sure the installation API is available
      include_once $this->root . '/core/includes/install.inc';
    }

    $name = $name ?: $module;
    $key = $type . ':' . $module . ':' . $name;
    if (isset($this->includeFileKeys[$key])) {
      return $this->includeFileKeys[$key];
    }
    if (isset($this->moduleList[$module])) {
      $file = $this->root . '/' . $this->moduleList[$module]->getPath() . "/$name.$type";
      if (is_file($file)) {
        require_once $file;
        $this->includeFileKeys[$key] = $file;
        return $file;
      }
      else {
        $this->includeFileKeys[$key] = FALSE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getHookInfo() {
    if (!isset($this->hookInfo)) {
      if ($cache = $this->cacheBackend->get('hook_info')) {
        $this->hookInfo = $cache->data;
      }
      else {
        $this->buildHookInfo();
        $this->cacheBackend->set('hook_info', $this->hookInfo);
      }
    }
    return $this->hookInfo;
  }

  /**
   * Builds hook_hook_info() information.
   *
   * @see \Drupal\Core\Extension\ModuleHandler::getHookInfo()
   */
  protected function buildHookInfo() {
    $this->hookInfo = [];
    // Make sure that the modules are loaded before checking.
    $this->reload();
    // $this->invokeAll() would cause an infinite recursion.
    foreach ($this->moduleList as $module => $filename) {
      $function = $module . '_hook_info';
      if (function_exists($function)) {
        $result = $function();
        if (isset($result) && is_array($result)) {
          $this->hookInfo = NestedArray::mergeDeep($this->hookInfo, $result);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function writeCache() {
    if ($this->cacheNeedsWriting) {
      $this->cacheBackend->set('module_implements', $this->implementations);
      $this->cacheNeedsWriting = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resetImplementations() {
    $this->implementations = NULL;
    $this->hookInfo = NULL;
    $this->alterFunctions = NULL;
    // We maintain a persistent cache of hook implementations in addition to the
    // static cache to avoid looping through every module and every hook on each
    // request. Benchmarks show that the benefit of this caching outweighs the
    // additional database hit even when using the default database caching
    // backend and only a small number of modules are enabled. The cost of the
    // $this->cacheBackend->get() is more or less constant and reduced further
    // when non-database caching backends are used, so there will be more
    // significant gains when a large number of modules are installed or hooks
    // invoked, since this can quickly lead to
    // \Drupal::moduleHandler()->hasImplementations() being called several
    // thousand times per request.
    $this->cacheBackend->set('module_implements', []);
    $this->cacheBackend->delete('hook_info');
  }

  /**
   * {@inheritdoc}
   */
  public function hasImplementations(string $hook, $modules = NULL): bool {
    if ($modules !== NULL) {
      foreach ((array) $modules as $module) {
        // Hook implementations usually found in a module's .install file are
        // not stored in the implementation info cache. In order to invoke hooks
        // like hook_schema() and hook_requirements() the module's .install file
        // must be included by the calling code. Additionally, this check avoids
        // unnecessary work when a hook implementation is present in a module's
        // .module file.
        if (function_exists($module . '_' . $hook)) {
          return TRUE;
        }
      }
    }

    $implementations = $this->getImplementationInfo($hook);
    if ($modules === NULL && !empty($implementations)) {
      return TRUE;
    }

    return !empty(array_intersect((array) $modules, array_keys($implementations)));
  }

  /**
   * {@inheritdoc}
   */
  public function invokeAllWith(string $hook, callable $callback): void {
    foreach (array_keys($this->getImplementationInfo($hook)) as $module) {
      $hookInvoker = \Closure::fromCallable($module . '_' . $hook);
      $callback($hookInvoker, $module);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invoke($module, $hook, array $args = []) {
    if (!$this->hasImplementations($hook, $module)) {
      return;
    }
    $hookInvoker = \Closure::fromCallable($module . '_' . $hook);
    return call_user_func_array($hookInvoker, $args);
  }

  /**
   * {@inheritdoc}
   */
  public function invokeAll($hook, array $args = []) {
    $return = [];
    $this->invokeAllWith($hook, function (callable $hook, string $module) use ($args, &$return) {
      $result = call_user_func_array($hook, $args);
      if (isset($result) && is_array($result)) {
        $return = NestedArray::mergeDeep($return, $result);
      }
      elseif (isset($result)) {
        $return[] = $result;
      }
    });
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function invokeDeprecated($description, $module, $hook, array $args = []) {
    $result = $this->invoke($module, $hook, $args);
    $this->triggerDeprecationError($description, $hook);
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function invokeAllDeprecated($description, $hook, array $args = []) {
    $result = $this->invokeAll($hook, $args);
    $this->triggerDeprecationError($description, $hook);
    return $result;
  }

  /**
   * Triggers an E_USER_DEPRECATED error if any module implements the hook.
   *
   * @param string $description
   *   Helpful text describing what to do instead of implementing this hook.
   * @param string $hook
   *   The name of the hook.
   */
  private function triggerDeprecationError($description, $hook) {
    $modules = array_keys($this->getImplementationInfo($hook));
    if (!empty($modules)) {
      $message = 'The deprecated hook hook_' . $hook . '() is implemented in these functions: ';
      $implementations = array_map(function ($module) use ($hook) {
        return $module . '_' . $hook . '()';
      }, $modules);
      @trigger_error($message . implode(', ', $implementations) . '. ' . $description, E_USER_DEPRECATED);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alter($type, &$data, &$context1 = NULL, &$context2 = NULL) {
    // Most of the time, $type is passed as a string, so for performance,
    // normalize it to that. When passed as an array, usually the first item in
    // the array is a generic type, and additional items in the array are more
    // specific variants of it, as in the case of array('form', 'form_FORM_ID').
    if (is_array($type)) {
      $cid = implode(',', $type);
      $extra_types = $type;
      $type = array_shift($extra_types);
      // Allow if statements in this function to use the faster isset() rather
      // than !empty() both when $type is passed as a string, or as an array
      // with one item.
      if (empty($extra_types)) {
        unset($extra_types);
      }
    }
    else {
      $cid = $type;
    }

    // Some alter hooks are invoked many times per page request, so store the
    // list of functions to call, and on subsequent calls, iterate through them
    // quickly.
    if (!isset($this->alterFunctions[$cid])) {
      $this->alterFunctions[$cid] = [];
      $hook = $type . '_alter';
      $modules = array_keys($this->getImplementationInfo($hook));
      if (!isset($extra_types)) {
        // For the more common case of a single hook, we do not need to call
        // function_exists(), since $this->getImplementationInfo() returns only
        // modules with implementations.
        foreach ($modules as $module) {
          $this->alterFunctions[$cid][] = $module . '_' . $hook;
        }
      }
      else {
        // For multiple hooks, we need $modules to contain every module that
        // implements at least one of them.
        $extra_modules = [];
        foreach ($extra_types as $extra_type) {
          $extra_modules[] = array_keys($this->getImplementationInfo($extra_type . '_alter'));
        }
        $extra_modules = array_merge(...$extra_modules);
        // If any modules implement one of the extra hooks that do not implement
        // the primary hook, we need to add them to the $modules array in their
        // appropriate order. $this->getImplementationInfo() can only return
        // ordered implementations of a single hook. To get the ordered
        // implementations of multiple hooks, we mimic the
        // $this->getImplementationInfo() logic of first ordering by
        // $this->getModuleList(), and then calling
        // $this->alter('module_implements').
        if (array_diff($extra_modules, $modules)) {
          // Merge the arrays and order by getModuleList().
          $modules = array_intersect(array_keys($this->moduleList), array_merge($modules, $extra_modules));
          // Since $this->getImplementationInfo() already took care of loading the
          // necessary include files, we can safely pass FALSE for the array
          // values.
          $implementations = array_fill_keys($modules, FALSE);
          // Let modules adjust the order solely based on the primary hook. This
          // ensures the same module order regardless of whether this if block
          // runs. Calling $this->alter() recursively in this way does not
          // result in an infinite loop, because this call is for a single
          // $type, so we won't end up in this code block again.
          $this->alter('module_implements', $implementations, $hook);
          $modules = array_keys($implementations);
        }
        foreach ($modules as $module) {
          // Since $modules is a merged array, for any given module, we do not
          // know whether it has any particular implementation, so we need a
          // function_exists().
          $function = $module . '_' . $hook;
          if (function_exists($function)) {
            $this->alterFunctions[$cid][] = $function;
          }
          foreach ($extra_types as $extra_type) {
            $function = $module . '_' . $extra_type . '_alter';
            if (function_exists($function)) {
              $this->alterFunctions[$cid][] = $function;
            }
          }
        }
      }
    }

    foreach ($this->alterFunctions[$cid] as $function) {
      $function($data, $context1, $context2);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterDeprecated($description, $type, &$data, &$context1 = NULL, &$context2 = NULL) {
    // Invoke the alter hook. This has the side effect of populating
    // $this->alterFunctions.
    $this->alter($type, $data, $context1, $context2);
    // The $type parameter can be an array. alter() will deal with this
    // internally, but we have to extract the proper $cid in order to discover
    // implementations.
    $cid = $type;
    if (is_array($type)) {
      $cid = implode(',', $type);
      $extra_types = $type;
      $type = array_shift($extra_types);
    }
    if (!empty($this->alterFunctions[$cid])) {
      $message = 'The deprecated alter hook hook_' . $type . '_alter() is implemented in these functions: ' . implode(', ', $this->alterFunctions[$cid]) . '.';
      @trigger_error($message . ' ' . $description, E_USER_DEPRECATED);
    }
  }

  /**
   * Provides information about modules' implementations of a hook.
   *
   * @param string $hook
   *   The name of the hook (e.g. "help" or "menu").
   *
   * @return mixed[]
   *   An array whose keys are the names of the modules which are implementing
   *   this hook and whose values are either a string identifying a file in
   *   which the implementation is to be found, or FALSE, if the implementation
   *   is in the module file.
   */
  protected function getImplementationInfo($hook) {
    if (!isset($this->implementations)) {
      $this->implementations = [];
      $this->verified = [];
      if ($cache = $this->cacheBackend->get('module_implements')) {
        $this->implementations = $cache->data;
      }
    }
    if (!isset($this->implementations[$hook])) {
      // The hook is not cached, so ensure that whether or not it has
      // implementations, the cache is updated at the end of the request.
      $this->cacheNeedsWriting = TRUE;
      // Discover implementations.
      $this->implementations[$hook] = $this->buildImplementationInfo($hook);
      // Implementations are always "verified" as part of the discovery.
      $this->verified[$hook] = TRUE;
    }
    elseif (!isset($this->verified[$hook])) {
      if (!$this->verifyImplementations($this->implementations[$hook], $hook)) {
        // One or more of the implementations did not exist and need to be
        // removed in the cache.
        $this->cacheNeedsWriting = TRUE;
      }
      $this->verified[$hook] = TRUE;
    }
    return $this->implementations[$hook];
  }

  /**
   * Builds hook implementation information for a given hook name.
   *
   * @param string $hook
   *   The name of the hook (e.g. "help" or "menu").
   *
   * @return mixed[]
   *   An array whose keys are the names of the modules which are implementing
   *   this hook and whose values are either a string identifying a file in
   *   which the implementation is to be found, or FALSE, if the implementation
   *   is in the module file.
   *
   * @throws \RuntimeException
   *   Exception thrown when an invalid implementation is added by
   *   hook_module_implements_alter().
   *
   * @see \Drupal\Core\Extension\ModuleHandler::getImplementationInfo()
   */
  protected function buildImplementationInfo($hook) {
    $implementations = [];
    $hook_info = $this->getHookInfo();
    foreach ($this->moduleList as $module => $extension) {
      $include_file = isset($hook_info[$hook]['group']) && $this->loadInclude($module, 'inc', $module . '.' . $hook_info[$hook]['group']);
      // Since $this->implementsHook() may needlessly try to load the include
      // file again, function_exists() is used directly here.
      if (function_exists($module . '_' . $hook)) {
        $implementations[$module] = $include_file ? $hook_info[$hook]['group'] : FALSE;
      }
    }
    // Allow modules to change the weight of specific implementations, but avoid
    // an infinite loop.
    if ($hook != 'module_implements_alter') {
      // Remember the original implementations, before they are modified with
      // hook_module_implements_alter().
      $implementations_before = $implementations;
      // Verify implementations that were added or modified.
      $this->alter('module_implements', $implementations, $hook);
      // Verify new or modified implementations.
      foreach (array_diff_assoc($implementations, $implementations_before) as $module => $group) {
        // If an implementation of hook_module_implements_alter() changed or
        // added a group, the respective file needs to be included.
        if ($group) {
          $this->loadInclude($module, 'inc', "$module.$group");
        }
        // If a new implementation was added, verify that the function exists.
        if (!function_exists($module . '_' . $hook)) {
          throw new \RuntimeException("An invalid implementation {$module}_{$hook} was added by hook_module_implements_alter()");
        }
      }
    }
    return $implementations;
  }

  /**
   * Verifies an array of implementations loaded from cache.
   *
   * Verification is done by including the lazy-loaded $module.$group.inc file,
   * and checking function_exists().
   *
   * @param string[] $implementations
   *   Implementation "group" by module name.
   * @param string $hook
   *   The hook name.
   *
   * @return bool
   *   TRUE, if all implementations exist.
   *   FALSE, if one or more implementations don't exist and need to be removed
   *     from the cache.
   */
  protected function verifyImplementations(&$implementations, $hook) {
    $all_valid = TRUE;
    foreach ($implementations as $module => $group) {
      // If this hook implementation is stored in a lazy-loaded file, include
      // that file first.
      if ($group) {
        $this->loadInclude($module, 'inc', "$module.$group");
      }
      // It is possible that a module removed a hook implementation without
      // the implementations cache being rebuilt yet, so we check whether the
      // function exists on each request to avoid undefined function errors.
      // Since ModuleHandler::implementsHook() may needlessly try to
      // load the include file again, function_exists() is used directly here.
      if (!function_exists($module . '_' . $hook)) {
        // Clear out the stale implementation from the cache and force a cache
        // refresh to forget about no longer existing hook implementations.
        unset($implementations[$module]);
        // One of the implementations did not exist and needs to be removed in
        // the cache.
        $all_valid = FALSE;
      }
    }
    return $all_valid;
  }

  /**
   * {@inheritdoc}
   */
  public function getModuleDirectories() {
    $dirs = [];
    foreach ($this->getModuleList() as $name => $module) {
      $dirs[$name] = $this->root . '/' . $module->getPath();
    }
    return $dirs;
  }

  /**
   * {@inheritdoc}
   */
  public function getName($module) {
    @trigger_error(__METHOD__ . '() is deprecated in drupal:10.3.0 and is removed from drupal:12.0.0. Use \Drupal\Core\Extension\ModuleExtensionList::getName($module) instead. See https://www.drupal.org/node/3310017', E_USER_DEPRECATED);
    return \Drupal::service('extension.list.module')->getName($module);
  }

}

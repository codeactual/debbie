<?php
/**
 * Debbie class.
 *
 * @package Debbie
 */

namespace Debbie;

use \Exception;

/**
 * Debian package generator.
 */
class Debbie
{
  /**
   * @const Default value of $this->config['workspaceBasedir'].
   */
  const DEFAULT_WORKSPACE_BASEDIR = '/var/tmp/debbie';

  /**
   * @const gmdate() format for default $this->config['buildTime'] value.
   */
  const DEFAULT_BUILDTIME_FORMAT = 'Ymd-His';

  /**
   * @var array Configuration key/value pairs.
   * - string 'arch' Package arch, e.g. 'amd64'.
   * - string 'buildDir' Build dir absolute path (under $versionDir).
   * - string 'buildTime' Timestamp.
   * - string 'depends' Package dependencies in "control" file format.
   * - array 'exclude' `rsync` --exclude values filtering source directories.
   * - string 'fullName' Package full name e.g. 'wget_1.12-2.1_amd64'.
   * - string 'pkgDir' Source files absolute path (under $buildDir).
   * - string 'postinst' Shell script body ran after installation.
   * - string 'section' Package section, e.g. 'web'.
   * - string 'shortName' Package short name, e.g. 'wget'.
   * - array 'sources' Source file/dir objects.
   *   string 'src' Absolute path to source file or dir.
   *   string 'dst' (Optional, '') Absolute path to install location.
   * - string 'version' Package version, e.g. '1.12-2.1'.
   * - string 'versionDir' Version dir absolute path.
   * - string 'workspaceBasedir' Base directory for all work files.
   */
  protected $config;

  /**
   * Store configs and create the initial directories.
   *
   * @param array $this->config key/value pairs.
   * - 'arch' (Optional, 'all')
   * - 'buildTime' (Optional, current UTC in Ymd-His format)
   * - 'depends' (Optional, array())
   * - 'exclude' (Optional, array())
   * - 'postinst' (Optional, '')
   * - 'workspaceBasedir' (Optional, '/var/tmp/debbie')
   * @param string $shortName Deb filename: <short>_<version>_<arch>.
   * @param string $version
   * @param string $depends (optional, '') Package dependency list.
   * @param string $section (Optional, 'web') Package section.
   * @param string $arch (Optional, 'all') Package target architecture.
   * @param string $buildTime (Optional, gmdate('Ymd-His)) Timestamp appended to the build dir name.
   */
  public function __construct(array $config)
  {
    $config = $this->applyConfigDefaults($config);
    $this->validateConfig($config);
    $this->config = $config;

    if (!file_exists($this->config['pkgDir'])) {
      $this->runCmd("mkdir -p {$this->config['pkgDir']}");
    }
  }

  /**
   * Merge defaults with user-defined options.
   *
   * @param array $config See __construct() for structure.
   * @return array
   */
  public function applyConfigDefaults(array $config)
  {
    $defaults = array(
      'arch' => 'all',
      'buildTime' =>  gmdate(self::DEFAULT_BUILDTIME_FORMAT),
      'description' => '',
      'depends' => '',
      'exclude' => array(),
      'maintainer' => '',
      'postinst' => '',
      'priority' => 'optional',
      'sources' => array(),
      'workspaceBasedir' => self::DEFAULT_WORKSPACE_BASEDIR
    );
    $config = array_merge($defaults, $config);

    $config['sources'] = array();
    $config['fullName'] = sprintf(
      '%s_%s_%s',
      $config['shortName'], $config['version'], $config['arch']
    );
    $config['versionDir'] = sprintf(
      '%s/%s/%s',
      $config['workspaceBasedir'], $config['shortName'], $config['version']
    );
    $config['buildDir'] = "{$config['versionDir']}/" . $config['buildTime'];
    $config['pkgDir'] = "{$config['buildDir']}/{$config['fullName']}";

    return $config;
  }

  /**
   * Return $this->config keys which must have non-empty values.
   * - Isolated for unit test use.
   *
   * @return array
   */
  public function getNonEmptyConfigs()
  {
    return array('arch', 'buildTime', 'shortName', 'version', 'workspaceBasedir');
  }

  /**
   * Validate a potential $this->config value.
   *
   * @param array $config
   * @return void
   * @throw Exception
   * - on invalid value
   * - on missing key
   */
  public function validateConfig(array $config)
  {
    $nonEmpty = $this->getNonEmptyConfigs();
    foreach ($nonEmpty as $key) {
      if (empty($config[$key])) {
        throw new Exception("{$key} configuration value is required");
      }
    }
  }

  /**
   * Run a shell command.
   *
   * @return void
   * @throw Exception
   * - on non-zero exit code
   */
  public function runCmd($cmd)
  {
    $returnVar = null;
    passthru($cmd, $returnVar);
    if ($returnVar !== 0) {
      throw Exception("{$cmd}: exited with code {$returnVar}");
    }
  }

  /**
   * Build the .deb.
   *
   * @return string Absolute path to final package.
   */
  public function build()
  {
    $prevCwd = getcwd();

    // Trailing newline required by `dpkg-deb`.
    $this->config['description'] = trim($this->config['description']) . "\n";

    $control = <<<CONTROL
Package: {$this->config['shortName']}
Version: {$this->config['version']}
Section: {$this->config['section']}
Priority: {$this->config['priority']}
Architecture: {$this->config['arch']}
Depends: {$this->config['depends']}
Maintainer: {$this->config['maintainer']}
Description: {$this->config['description']}
CONTROL;

    // Create a workspace subdir.
    $debDir = "{$this->config['pkgDir']}/DEBIAN";
    $this->runCmd("mkdir -p {$debDir}");
    file_put_contents("{$debDir}/control", $control);

    // Write hook scripts (e.g. post installation).
    $script_names = array('postinst');
    foreach ($script_names as $name) {
      // @codeCoverageIgnoreStart
      if ($this->config[$name]) {
        file_put_contents("{$debDir}/{$name}", $this->config[$name]);
        chmod("{$debDir}/{$name}", 0755);
      }
      // @codeCoverageIgnoreEnd
    }

    // Copy all source files to the workspace.
    if ($this->config['sources']) {
      // Used outside the loop for a follow-up task. More docs there.
      $sourceFilePresent = false;

      foreach ($this->config['sources'] as $source) {
        // Rewrite destination directories as rooted in 'pkgDir'.
        if ($source['dst']) {
          $customDst = true;
          $source['dst'] = "{$this->config['pkgDir']}/" . ltrim($source['dst'], '/');
        } else {
          $customDst = false;
          $source['dst'] = "{$this->config['pkgDir']}/";
          if (is_dir($source['src'])) {
            $source['dst'] .= ltrim($source['src'], '/');
          } else {
            $source['dst'] .= ltrim(dirname($source['src']), '/');
          }
        }

        if (!file_exists($source['dst'])) {
          $this->runCmd("mkdir -p {$source['dst']}");
        }

        // Use `cp` or `rsync` to copy the files from / to the workspace root.
        if (is_file($source['src'])) {
          $this->runCmd("cp -a {$source['src']} {$source['dst']}");
          $sourceFilePresent = true;
        } else {
          $exclusions = array();
          foreach ($this->config['exclude'] as $exclude) {
            $exclusions[] = "--exclude={$exclude}";
          }
          $this->runCmd(
            sprintf(
              "rsync --recursive %s %s %s",
              implode(' ', $exclusions),
              $source['src'],
              $customDst ? $source['dst'] : dirname($source['dst'])
            )
          );
        }
      }

      // At least 1 source is a file (e.g. not a meta-package),
      // so include an MD5 manifest.
      if ($sourceFilePresent) {
        chdir($this->config['pkgDir']);
        try {
          $this->runCmd("md5sum `find . -type f | grep -v '^[.]/DEBIAN/'` >DEBIAN/md5sums");
        } catch (Exception $e) {
          chdir($prevCwd);
          throw $e;
        }
      }
    }

    chdir($this->config['buildDir']);
    try {
      $this->runCmd("dpkg-deb -b {$this->config['fullName']}");
    } catch (Exception $e) {
      chdir($prevCwd);
      throw $e;
    }

    chdir($prevCwd);
    return "{$this->config['buildDir']}/{$this->config['fullName']}.deb";
  }

  /**
   * Add package source file.
   *
   * @param string $src Absolute path to source file or dir.
   * @param string $dst (Optional, '') Absolute path to install location.
   * @return void
   */
  public function addSource($src, $dst = '')
  {
    $this->config['sources'][] = array('src' => $src, 'dst' => $dst);
  }

  /**
   * Write access to $this->postinst
   *
   * @param string $postinst
   * @return void
   * @throws Exception
   * - on missing shebang
   */
  public function setPostinst($postinst)
  {
    if (0 !== strpos($postinst, '#!/')) {
      throw new Exception("{$this->config['shortName']}: shebang directive required");
    }

    // @codeCoverageIgnoreStart
    $this->config['postinst'] = rtrim($postinst, "\n") . "\n";
  }
  // @codeCoverageIgnoreEnd

  /**
   * Read access to $this->config.
   *
   * @return array
   */
  public function getConfig()
  {
    return $this->config;
  }
}

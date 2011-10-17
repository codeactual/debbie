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
   * @const gmdate() format for default $this->config['buildId'] value.
   */
  const DEFAULT_BUILDTIME_FORMAT = 'Ymd-His';

  /**
   * @var array Configuration key/value pairs.
   * - string 'arch' Package arch, e.g. 'amd64'.
   * - string 'buildDir' Build dir absolute path (under $versionDir).
   * - string 'buildId' Timestamp, domain-specific ID, etc.
   * - string 'depends' Package dependencies in "control" file format.
   * - array 'exclude' `rsync` --exclude values filtering source directories.
   * - string 'fullName' Package full name e.g. 'wget_1.12-2.1_amd64'.
   * - string 'pkgDir' Source files absolute path (under $buildDir).
   * - string 'postinst' Shell script body ran after installation.
   *   Shebang required.
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
   * @var string Last output produced by runCmd().
   */
  protected $output;

  /**
   * Store configs and create the initial directories.
   *
   * @param array $this->config key/value pairs.
   * - Required keys:
   *   'description'
   *   'maintainer'
   *   'section'
   *   'shortName'
   *   'version'
   * - Optional keys and their default values:
   *   'arch': 'all'
   *   'buildId': Current UTC in Ymd His format
   *   'depends': array()
   *   'exclude': array()
   *   'postinst': ''
   *   'workspaceBasedir': '/var/tmp/debbie'
   * - Remaining keys may be overridable via methods like setPostinst().
   * @param string $shortName Deb filename: <short>_<version>_<arch>.
   * @param string $version
   * @param string $depends (optional, '') Package dependency list.
   * @param string $section (Optional, 'web') Package section.
   * @param string $arch (Optional, 'all') Package target architecture.
   * @param string $buildId (Optional, gmdate('Ymd-His))
   * - Unique string appended to the build dir name.
   */
  public function __construct(array $config)
  {
    $config = $this->applyConfigDefaults($config);
    $this->validateConfig($config);
    $this->config = $config;
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
      'buildId' =>  gmdate(self::DEFAULT_BUILDTIME_FORMAT),
      'description' => '',
      'depends' => array(),
      'exclude' => array(),
      'maintainer' => '',
      'postinst' => '',
      'priority' => 'optional',
      'sources' => array(),
      'workspaceBasedir' => self::DEFAULT_WORKSPACE_BASEDIR
    );
    $config = array_merge($defaults, $config);

    if ($config['postinst']) {
      $config['postinst'] = rtrim($config['postinst']) . "\n";
    }
    $config['sources'] = array();

    $config['fullName'] = sprintf(
      '%s_%s_%s',
      $config['shortName'], $config['version'], $config['arch']
    );
    $config['versionDir'] = sprintf(
      '%s/%s/%s',
      $config['workspaceBasedir'], $config['shortName'], $config['version']
    );
    $config['buildDir'] = "{$config['versionDir']}/" . $config['buildId'];
    $config['pkgDir'] = "{$config['buildDir']}/{$config['fullName']}";

    return $config;
  }

  /**
   * Return $this->config keys which must have non-empty values.
   * - Isolated for unit test use.
   *
   * @return array
   */
  public function getNonEmptyConfigKeys()
  {
    return array('arch', 'buildId', 'shortName', 'version', 'workspaceBasedir');
  }

  /**
   * Validate a potential $this->config value.
   *
   * @param array $config
   * @return void
   * @throws Exception
   * - on invalid value
   * - on missing key
   */
  public function validateConfig(array $config)
  {
    $nonEmpty = $this->getNonEmptyConfigKeys();
    foreach ($nonEmpty as $key) {
      if (empty($config[$key])) {
        throw new Exception("{$key} configuration value is required");
      }
    }
    if ($config['postinst'] && 0 !== strpos($config['postinst'], '#!/')) {
      throw new Exception("{$config['shortName']}: shebang directive required");
    }
  }

  /**
   * Run a shell command.
   *
   * @param string $cmd
   * @return void
   * @throws Exception
   * - on non-zero exit code
   */
  public function runCmd($cmd)
  {
    $returnVar = null;
    $lines = array();
    exec($cmd, $lines, $returnVar);
    $this->output = implode("\n", $lines);
    if ($returnVar !== 0) {
      throw Exception("{$cmd}: exited with code {$returnVar}");
    }
  }

  /**
   * Read access to $this->output.
   *
   * @return string.
   */
  public function getOutput()
  {
    return $this->output;
  }

  /**
   * Build the .deb.
   *
   * @return string Absolute path to final package.
   */
  public function build()
  {
    // Use before method exit (including exceptions) for restoration.
    $prevCwd = getcwd();

    // Trailing newline required by `dpkg-deb`.
    $this->config['description'] = trim($this->config['description']) . "\n";

    // Create a workspace subdir for this specific build.
    //
    // Example hierarchy where 'workspaceBasedir' is '/tmp/myworkspace':
    // tmp/
    //   myworkspace/
    //     mypackage/
    //       2.0/
    //         2011-11-10/
    //           mypackage_2.0_amd64/
    //             sourcedir1/
    //               sourcefile
    //             sourcedir2/
    //               sourcefile
    //             DEBIAN/
    //               control
    //               md5sums
    //           mypackage_2.0_amd.deb
    if (!file_exists($this->config['pkgDir'])) {
      $this->runCmd("mkdir -p {$this->config['pkgDir']}");
    }

    // Write hook scripts (e.g. for post installation).
    $debDir = "{$this->config['pkgDir']}/DEBIAN";
    $this->runCmd("mkdir {$debDir}");
    $script_names = array('postinst');
    foreach ($script_names as $name) {
      // @codeCoverageIgnoreStart
      if ($this->config[$name]) {
        file_put_contents("{$debDir}/{$name}", $this->config[$name]);
        chmod("{$debDir}/{$name}", 0755);
      }
      // @codeCoverageIgnoreEnd
    }

    $depends = implode(', ', $this->config['depends']);

    // Write info stored in /var/lib/dpkg/available after installation.
    $control = <<<CONTROL
Package: {$this->config['shortName']}
Version: {$this->config['version']}
Section: {$this->config['section']}
Priority: {$this->config['priority']}
Architecture: {$this->config['arch']}
Depends: {$depends}
Maintainer: {$this->config['maintainer']}
Description: {$this->config['description']}
CONTROL;
    file_put_contents("{$debDir}/control", $control);

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

        // Use `cp` or `rsync` to copy the files rooted in / to this build's root.
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
   * Read access to $this->config.
   *
   * @return array
   */
  public function getConfig()
  {
    return $this->config;
  }
}

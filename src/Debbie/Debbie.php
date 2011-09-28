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
   * - 'depends' (Optional, '')
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
      'depends' => '',
      'postinst' => '',
      'sources' => array(),
      'workspaceBasedir' => self::DEFAULT_WORKSPACE_BASEDIR
    );
    $config = array_merge($defaults, $config);

    $config['fullName'] = sprintf(
      '%s_%s_%s',
      $config['shortName'], $config['version'], $config['arch']
    );
    $config['sources'] = array();
    $config['versionDir'] = sprintf(
      '%s/%s/%s',
      $config['workspaceBasedir'], $config['shortName'], $config['version']
    );
    $config['buildDir'] = "{$config['versionDir']}/" . $config['buildTime'];
    $config['pkgDir'] = "{$config['buildDir']}/{$config['fullName']}";

    return $config;
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
    /*foreach ($config as $key => $val) {
      if (empty($val)) {
        throw new Exception("{$key} configuration value is required");
      }
    }*/

    if (!file_exists($config['pkgDir'])) {
      $this->runCmd("mkdir -p {$config['pkgDir']}");
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
   * Build package using collected settings.
   *
   * @return string Absolute path to built package.
   */
  public function build()
  {
    // trailing newline required
    $description = "Automated build of {$this->config['fullName']} ({$this->config['buildTime']})\n";

    $control = <<<CONTROL
Package: {$this->config['shortName']}
Version: {$this->config['version']}
Section: {$this->config['section']}
Priority: optional
Architecture: {$this->config['arch']}
Depends: {$this->config['depends']}
Maintainer: Code Actual
Description: {$description}
CONTROL;

    // prepare packaging
    $debDir = "{$this->config['pkgDir']}/DEBIAN";
    $this->runCmd("mkdir -p {$debDir}");
    file_put_contents("{$debDir}/control", $control);

    // create event scripts
    $script_names = array('postinst');
    foreach ($script_names as $name) {
      if ($this->config[$name]) {
        // @codeCoverageIgnoreStart
        file_put_contents("{$debDir}/{$name}", $this->config[$name]);
        chmod("{$debDir}/{$name}", 0755);
      }
      // @codeCoverageIgnoreEnd
    }

    // copy package source files
    if ($this->config['sources']) {
      $md5Required = false;
      foreach ($this->config['sources'] as $file) {
        if ($file['dst']) {
          $customDst = true;
          $file['dst'] = "{$this->config['pkgDir']}/" . ltrim($file['dst'], '/');
        } else {
          $customDst = false;
          $file['dst'] = "{$this->config['pkgDir']}/";
          if (is_dir($file['src'])) {
            $file['dst'] .= ltrim($file['src'], '/');
          } else {
            $file['dst'] .= ltrim(dirname($file['src']), '/');
          }
        }

        if (!file_exists($file['dst'])) {
          $this->runCmd("mkdir -p {$file['dst']}");
        }

        $defaultCp = "cp -a {$file['src']} {$file['dst']}";

        if (is_file($file['src'])) {
          $this->runCmd($defaultCp);
          $md5Required = true;
        } else {
          $exclusions = array(
            "--exclude='.[^.]*'", '--exclude=cache', '--exclude=tmp',
            '--exclude=temp', '--exclude=doc', '--exclude=docs'
          );
          $this->runCmd(
            sprintf(
              "rsync --recursive %s %s %s",
              implode(' ', $exclusions),
              $file['src'],
              $customDst ? $file['dst'] : dirname($file['dst'])
            )
          );
        }
      }

      // at least 1 source is a file
      if ($md5Required) {
        chdir($this->config['pkgDir']);
        $this->runCmd("md5sum `find . -type f | grep -v '^[.]/DEBIAN/'` >DEBIAN/md5sums");
      }
    }

    // build package
    chdir($this->config['buildDir']);
    $this->runCmd("dpkg-deb -b {$this->config['fullName']}");

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

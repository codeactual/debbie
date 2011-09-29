<?php
/**
 * Requires sudo access to `dpkg`.
 */

use \Debbie\Debbie;

require_once __DIR__ . '/../src/Debbie/Debbie.php';

class DebbieTest extends PHPUnit_Framework_TestCase
{
  public function setUp()
  {
    parent::setUp();

    // All options defined.
    $this->maxConfig = array(
      'arch' => 'amd64',
      'buildTime' => '2011-11-10',
      'description' => 'Test package for Debbie class',
      'maintainer' => 'DebbieTest Author <debbie@codeactual.com>',
      'postinst' => "#!/bin/sh\necho ''",
      'section' => 'test',
      'shortName' => 'debbie-test',
      'version' => '1.2.3-4.5',
      'workspaceBasedir' => '/tmp/debbietest-workspace',
    );

    // Only required options defined.
    $this->minConfig = array(
      'description' => $this->maxConfig['description'],
      'maintainer' => $this->maxConfig['maintainer'],
      'section' => $this->maxConfig['section'],
      'shortName' => $this->maxConfig['shortName'],
      'version' => $this->maxConfig['version']
    );
  }

  public function tearDown()
  {
    parent::tearDown();

    if ($this->isDebInstalled($this->shortName)) {
      $this->uninstallDeb($this->shortName);
    }

    system("rm -rf {$this->maxConfig['workspaceBasedir']}/*");
  }

  /**
   * Get a deb's file list.
   *
   * @param string $file Package location.
   * @return string
   */
  public function getDebContents($file)
  {
    return shell_exec("dpkg-deb --contents {$file}");
  }

  /**
   * Get a deb's dpkg info.
   *
   * @param string $file Package location.
   * @return string
   */
  public function getDebInfo($file)
  {
    return shell_exec("dpkg-deb --info {$file}");
  }

  /**
   * Install a deb.
   *
   * @param string $file Package location.
   * @return void
   */
  public function installDeb($file)
  {
    $returnVar = '';
    system("sudo dpkg -i {$file}", $returnVar);
    $this->assertSame(0, $returnVar);
  }

  /**
   * Uninstall a deb.
   *
   * @param string $name Package short name.
   * @return void
   */
  public function uninstallDeb($name)
  {
    $returnVar = '';
    system("sudo dpkg -r {$name}", $returnVar);
    $this->assertSame(0, $returnVar);
  }

  /**
   * Verify a package is installed.
   *
   * @return bool
   */
  public function isDebInstalled($name)
  {
    return false !== strpos(shell_exec("dpkg --status {$name} 2>&1"), 'Status: install');
  }

  /**
   * Assert a Debbie::__construct() configuration array matches the dpkg-deb
   * information of a built package.
   *
   * @param string $info getDebInfo() output.
   * @param array $config
   * @return void
   */
  public function assertConfigMatchesDebInfo($info, array $config)
  {
    $this->assertContains("Package: {$config['shortName']}", $info);
    $this->assertContains("Version: {$config['version']}", $info);
    $this->assertContains("Section: {$config['section']}", $info);
    $this->assertContains("Priority: {$config['priority']}", $info);
    $this->assertContains("Architecture: {$config['arch']}", $info);
    $dependsStr = implode(', ', ($config['depends'] ?: array()));
    $this->assertContains("Depends: {$dependsStr}\n", $info);
    $this->assertContains("Maintainer: {$config['maintainer']}", $info);
    $this->assertContains("Description: {$config['description']}", $info);
  }

  /**
   * @group appliesDefaultConfig
   * @test
   */
  public function appliesDefaultConfig()
  {
    $deb = new Debbie($this->minConfig);
    $actual = $deb->getConfig();
    $this->assertSame('all', $actual['arch']);
    $this->assertSame(gmdate(Debbie::DEFAULT_BUILDTIME_FORMAT), $actual['buildTime']);
    $this->assertSame(array(), $actual['depends']);
    $this->assertSame(array(), $actual['exclude']);
    $this->assertSame('', $actual['postinst']);
    $this->assertSame('optional', $actual['priority']);
    $this->assertSame(array(), $actual['sources']);
    $this->assertSame(Debbie::DEFAULT_WORKSPACE_BASEDIR, $actual['workspaceBasedir']);
    $info = $this->getDebInfo($deb->build());
    $this->assertConfigMatchesDebInfo($info, $this->minConfig);
  }

  /**
   * @group appliesCustomConfig
   * @test
   */
  public function appliesCustomConfig()
  {
    $deb = new Debbie($this->maxConfig);
    $actual = $deb->getConfig();

    // applyConfigDefaults() adds this dpkg-deb requirement
    $this->maxConfig['postinst'] .= "\n";

    foreach ($this->maxConfig as $key => $value) {
      $this->assertSame($value, $actual[$key]);
    }

    $info = $this->getDebInfo($deb->build());
    $this->assertConfigMatchesDebInfo($info, $this->maxConfig);
  }

  /**
   * @group buildsFullName
   * @test
   */
  public function buildsFullName()
  {
    $deb = new Debbie($this->maxConfig);
    $actual = $deb->getConfig();
    $this->assertSame(
      "{$this->maxConfig['shortName']}_{$this->maxConfig['version']}_{$this->maxConfig['arch']}",
      $actual['fullName']
    );
  }

  /**
   * @group buildsPackageWorkspaceDir
   * @test
   */
  public function buildsPackageWorkspaceDir()
  {
    $deb = new Debbie($this->maxConfig);
    $actual = $deb->getConfig();
    $this->assertSame(
      sprintf(
        '%s/%s/%s/%s/%s',
        $this->maxConfig['workspaceBasedir'],
        $this->maxConfig['shortName'],
        $this->maxConfig['version'],
        $this->maxConfig['buildTime'],
        $actual['fullName']
      ),
      $actual['pkgDir']
    );
  }

  /**
   * @group detectsEmptyConfigs
   * @test
   */
  public function detectsEmptyConfigs()
  {
    $deb = new Debbie($this->maxConfig);
    $keys = $deb->getNonEmptyConfigKeys();
    foreach ($keys as $key) {
      $incompleteConfig = $this->maxConfig;
      try {
        unset($incompleteConfig[$key]);
        $deb->validateConfig($incompleteConfig);
        $this->fail("did not detect empty '{$key}'");
      } catch (Exception $e) {
        $this->assertContains(
          "{$key} configuration value is required",
          $e->getMessage()
        );
      }
    }
  }

  /**
   * @group detectsMissingSheBang
   * @test
   */
  public function detectsMissingSheBang()
  {
    $this->maxConfig['postinst'] = 'echo "text"';
    try {
      $deb = new Debbie($this->maxConfig);
      $this->fail('did not detect missing shebang');
    } catch (Exception $e) {
      $message = $e->getMessage();
    }
    $this->assertRegExp(
      '/' . $this->shortName . ': shebang directive required/',
      $message
    );
  }

  /**
   * @group acceptsPostInst
   * @test
   */
  public function acceptsPostInst()
  {
    $tmpfile = '/tmp/' . __CLASS__ . '-' . uniqid();
    $this->maxConfig['postinst'] = "#!/bin/sh\ntouch {$tmpfile}";
    $deb = new Debbie($this->maxConfig);
    $this->assertFalse(is_readable($tmpfile));
    $this->installDeb($deb->build());
    $this->assertTrue(is_readable($tmpfile), $tmpfile);
  }

  /**
   * @group copiesSourceFile
   * @test
   */
  public function copiesSourceFile()
  {
    $deb = new Debbie($this->maxConfig);
    $src = tempnam('/tmp', __CLASS__);
    $deb->addSource($src);
    $this->assertContains($src, $this->getDebContents($deb->build()));
  }

  /**
   * @group copiesSourceDir
   * @test
   */
  public function copiesSourceDir()
  {
    $deb = new Debbie($this->maxConfig);
    $src = '/tmp/' . uniqid();
    mkdir($src);
    $deb->addSource($src);
    $this->assertContains($src, $this->getDebContents($deb->build()));
  }

  /**
   * @group copiesSourceDirWithFile
   * @test
   */
  public function copiesSourceDirWithFile()
  {
    $deb = new Debbie($this->maxConfig);
    $srcDir = '/tmp/' . uniqid();
    mkdir($srcDir);
    $srcFile = "{$srcDir}/" . uniqid();
    touch($srcFile);
    $deb->addSource($srcDir);
    $this->assertContains($srcFile, $this->getDebContents($deb->build()));
  }

  /**
   * @group excludesByPatternInSourceDirs
   * @test
   */
  public function excludesByPatternInSourceDirs()
  {
    $this->maxConfig['exclude'] = array('\'.[^.]*\'');
    $deb = new Debbie($this->maxConfig);

    $baseSrcDir = '/tmp/' . uniqid();
    mkdir($baseSrcDir);
    $expectedFile = "{$baseSrcDir}/" . uniqid();
    touch($expectedFile);

    $dotDir = "{$baseSrcDir}/.someDotDir";
    mkdir($dotDir);
    $unexpectedFile = "{$dotDir}/" . uniqid();
    touch($unexpectedFile);

    $deb->addSource($baseSrcDir);
    $debList = $this->getDebContents($deb->build());
    $this->assertContains($expectedFile, $debList);
    $this->assertNotContains($unexpectedFile, $debList, $debList);
  }

  /**
   * addSource() allows a custom destination path for the installed file
   *
   * @group usesCustomDestination
   * @test
   */
  public function usesCustomDestination()
  {
    $deb = new Debbie($this->maxConfig);
    $src = '/tmp/' . uniqid();
    $dst = '/tmp/' . uniqid();
    $this->assertNotSame($src, $dst);
    mkdir($src);
    $deb->addSource($src, $dst);
    $list = $this->getDebContents($deb->build());
    $this->assertContains($dst, $list);
    $this->assertNotContains($src, $list);
  }
}

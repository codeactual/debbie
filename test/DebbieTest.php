<?php

use \Debbie\Debbie;

require_once __DIR__ . '/../src/Debbie/Debbie.php';

class DebbieTest extends PHPUnit_Framework_TestCase
{
  protected static $customWorkspaceBase = '/tmp/debbietest';

  public function setUp()
  {
    parent::setUp();

    $this->shortName = 'debbie-test-' . uniqid();
    $this->version = '1.2.3';
    $this->depends = array('pkg1', 'pkg2', 'pkg3');
    $this->dependsStr = implode(', ', $this->depends);
    $this->section = 'test';
    $this->arch = 'all';
  }

  /**
   * Get a deb's file list.
   *
   * @param string $file Package location.
   * @return string
   */
  public function getDebList($file)
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
   * @group validatesConfig
   * @test
   */
  public function validatesConfig()
  {
    // TODO check other tests for overlap
    $this->markTestIncomplete();
  }

  /**
   * @group appliesDefaultConfig
   * @test
   */
  public function appliesDefaultConfig()
  {
    // TODO check other tests for overlap
    $this->markTestIncomplete();
    /*$deb = new Debbie(
      array(
        'depends' => $this->depends,
        'shortName' => $this->shortName,
        'version' => $this->version,
      )
    );

    $versionDir = $deb->getVersionDir();
    $this->assertContains("{$this->shortName}/{$this->version}", $versionDir);

    $expectedMinute = date('Ymd-Hi');
    $buildDir = $deb->getBuildDir();
    $this->assertContains(
      "{$versionDir}/{$expectedMinute}",
      $buildDir
    );

    $pkgDir = $deb->getPkgDir();
    $this->assertContains(
      "{$buildDir}/{$this->shortName}_{$this->version}_all",
      $pkgDir
    );

    // custom
    $deb = new Debbie(
      array(
        'arch' => $this->arch,
        'depends' => $this->depends,
        'section' => $this->section,
        'shortName' => $this->shortName,
        'version' => $this->version
      )
    );
    $info = $this->getDebInfo($deb->build());
    $this->assertContains("Depends: {$this->dependsStr}", $info);
    $this->assertContains("Section: {$this->section}", $info);
    $this->assertContains("Architecture: {$this->arch}", $info);

    // defaults
    $deb = new Debbie(
      array(
        'shortName' => $this->shortName,
        'version' => $this->version,
      )
    );
    $info = $this->getDebInfo($deb->build());
    $this->assertContains("Depends: \n", $info);
    $this->assertContains('Section: web', $info);
    $this->assertContains('Architecture: all', $info);
     */
  }

  /**
   * @group copiesSourceFile
   * @test
   */
  public function copiesSourceFile()
  {
    $deb = new Debbie(
      array(
        'shortName' => $this->shortName,
        'version' => $this->version,
      )
    );
    $src = tempnam('/tmp', __CLASS__);
    $deb->addSource($src);
    $this->assertContains($src, $this->getDebList($deb->build()));
  }

  /**
   * @group copiesSourceDir
   * @test
   */
  public function copiesSourceDir()
  {
    $deb = new Debbie(
      array(
        'shortName' => $this->shortName,
        'version' => $this->version,
      )
    );
    $src = '/tmp/' . uniqid();
    mkdir($src);
    $deb->addSource($src);
    $this->assertContains($src, $this->getDebList($deb->build()));
  }

  /**
   * @group copiesSourceDirWithFile
   * @test
   */
  public function copiesSourceDirWithFile()
  {
    $deb = new Debbie(
      array(
        'shortName' => $this->shortName,
        'version' => $this->version,
      )
    );
    $srcDir = '/tmp/' . uniqid();
    mkdir($srcDir);
    $srcFile = "{$srcDir}/" . uniqid();
    touch($srcFile);
    $deb->addSource($srcDir);
    $this->assertContains($srcFile, $this->getDebList($deb->build()));
  }

  /**
   * @group skipsDotDirsInSourceDirs
   * @test
   */
  public function skipsDotDirsInSourceDirs()
  {
    $deb = new Debbie(
      array(
        'shortName' => $this->shortName,
        'version' => $this->version,
      )
    );

    $baseSrcDir = '/tmp/' . uniqid();
    mkdir($baseSrcDir);
    $expectedFile = "{$baseSrcDir}/" . uniqid();
    touch($expectedFile);

    $dotDir = "{$baseSrcDir}/.someDotDir";
    mkdir($dotDir);
    $unexpectedFile = "{$dotDir}/" . uniqid();
    touch($unexpectedFile);

    $deb->addSource($baseSrcDir);
    $debList = $this->getDebList($deb->build());
    $this->assertContains($expectedFile, $debList);
    $this->assertNotContains($unexpectedFile, $debList);
  }

  /**
   * addSource() allows a custom destination path for the installed file
   *
   * @group usesCustomDestination
   * @test
   */
  public function usesCustomDestination()
  {
    $deb = new Debbie(
      array(
        'shortName' => $this->shortName,
        'version' => $this->version,
      )
    );
    $src = '/tmp/' . uniqid();
    $dst = '/tmp/' . uniqid();
    $this->assertNotSame($src, $dst);
    mkdir($src);
    $deb->addSource($src, $dst);
    $list = $this->getDebList($deb->build());
    $this->assertContains($dst, $list);
    $this->assertNotContains($src, $list);
  }

  /**
   * @group acceptsPostInst
   * @test
   */
  public function acceptsPostInst()
  {
    if ('jenkins' == $_SERVER['LOGNAME']) {
      $this->markTestSkipped();
    }

    $deb = new Debbie(
      array(
        'shortName' => $this->shortName,
        'version' => $this->version,
      )
    );
    $tmpfile = '/tmp/' . __CLASS__ . '-' . uniqid();
    $this->assertFalse(is_readable($tmpfile));
    $postinst = "#!/bin/sh\ntouch {$tmpfile}";
    $deb->setPostinst($postinst);
    $this->installDeb($deb->build());
    $this->assertTrue(is_readable($tmpfile), $tmpfile);
    $this->uninstallDeb($this->shortName);
  }

  /**
   * @group throwsOnMissingSheBang
   * @test
   */
  public function throwsOnMissingSheBang()
  {
    $deb = new Debbie(
      array(
        'shortName' => $this->shortName,
        'version' => $this->version,
      )
    );
    try {
      $deb->setPostinst('echo "text"');
      $this->fail('did not detect missing shebang');
    } catch (Exception $e) {
      $message = $e->getMessage();
    }
    $this->assertRegExp(
      '/' . $this->shortName . ': shebang directive required/',
      $message
    );
  }
}

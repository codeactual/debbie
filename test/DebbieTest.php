<?php

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
      'shortName' => 'debbie-test-' . uniqid(),
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
   * @group validatesConfig
   * @test
   */
  public function validatesConfig()
  {
    // TODO check other tests for overlap
    $this->markTestIncomplete();
  }

  /**
   * @group appliesDefaultConfig2
   * @test
   */
  public function appliesDefaultConfig2()
  {
    // TODO check other tests for overlap
    $this->markTestIncomplete();
    /*$deb = new Debbie(
      array(
        'description' => $this->description,
        'depends' => $this->depends,
        'maintainer' => $this->maintainer,
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
        'description' => $this->description,
        'maintainer' => $this->maintainer,
        'section' => $this->section,
        'shortName' => $this->shortName,
        'version' => $this->version
      )
    );
    $info = $this->getDebInfo($deb->build());
      'depends' => array('pkg1', 'pkg2', 'pkg3'),
    $dependsStr = implode(', ', $this->maxConfig['depends']);
    $this->assertContains("Depends: {$dependsStr}", $info);
    $this->assertContains("Section: {$this->section}", $info);
    $this->assertContains("Architecture: {$this->arch}", $info);

    // defaults
    $deb = new Debbie(
      array(
        'description' => $this->description,
        'maintainer' => $this->maintainer,
        'shortName' => $this->shortName,
        'version' => $this->version,
      )
    );
    $info = $this->getDebInfo($deb->build());
    $this->assertContains("Depends: \n", $info);
    $this->assertContains('Section: web', $info);
    $this->assertContains('Architecture: all', $info);


    @TODO priority
    @TODO maintainer
    @TODO exclude
    @TODO description
    @TODO sources (e.g. allows empty for metapackage)
     */
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
   * @group skipsDotDirsInSourceDirs
   * @test
   */
  public function skipsDotDirsInSourceDirs()
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

  /**
   * @group acceptsPostInst
   * @test
   */
  public function acceptsPostInst()
  {
    // sudo dpkg requirement
    if ('jenkins' == $_SERVER['LOGNAME']) {
      $this->markTestSkipped();
    }

    $this->maxConfig['postinst'] = "#!/bin/sh\ntouch {$tmpfile}";
    $deb = new Debbie($this->maxConfig);
    $tmpfile = '/tmp/' . __CLASS__ . '-' . uniqid();
    $this->assertFalse(is_readable($tmpfile));
    $this->installDeb($deb->build());
    $this->assertTrue(is_readable($tmpfile), $tmpfile);
  }

  /**
   * @group throwsOnMissingSheBang
   * @test
   */
  public function throwsOnMissingSheBang()
  {
    $this->maxConfig['postinst'] = 'echo "text"';
    $deb = new Debbie($this->maxConfig);
    try {
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
   * @group excludesSourceFiles
   * @test
   */
  public function excludesSourceFiles()
  {
    $this->markTestIncomplete();
    $exclusions = array(
      "--exclude='.[^.]*'", '--exclude=cache', '--exclude=tmp',
      '--exclude=temp', '--exclude=doc', '--exclude=docs'
    );
  }
}

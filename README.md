# debbie

PHP class for building Debian packages.

High unit test coverage using PHPUnit.

## API

```$deb = new Debbie($config);```

<code>[addSource](https://github.com/codeactual/debbie/blob/524997a50999713ff7259ca953ac57e2236097cc/src/Debbie/Debbie.php#L321)($src, $dst = '')</code>

> Adds a file to the package manifest and an (optional) alternate install desintation.

<code>[build](https://github.com/codeactual/debbie/blob/524997a50999713ff7259ca953ac57e2236097cc/src/Debbie/Debbie.php#L191)()</code>

> Builds the `.deb` file and returns its location.

## Usage

``` php
<?php
$config = array(
  'arch' => 'amd64',
  'buildId' => '2011-11-10',
  'depends' => array('mysql-server'),
  'description' => 'Meta package for EC2 database master',
  'maintainer' => 'Package Author <you@gmail.com>',
  'postinst' => file_get_contents($postInstallScriptFile),
  'section' => 'db',
  'shortName' => 'ec2-dbmaster',
  'version' => '1.2',
  'workspaceBasedir' => '/tmp/deb-workspace/ec2-dbmaster'
);
$deb = new Debbie($config);
$deb->addSource('/etc/my.cnf');
$debFilename = $deb->build();
```

## Requirements

* PHP 5.3+

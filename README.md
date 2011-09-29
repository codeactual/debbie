# debbie

PHP class for building Debian packages.

## Usage

``` php
<?php
$config = array(
  'arch' => 'amd64',
  'buildId' => '2011-11-10',
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

# Flysystem adapter for Citrix ShareFile

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kapersoft/flysystem-sharefile.svg?style=flat-square)](https://packagist.org/packages/kapersoft/flysystem-sharefile)
[![Build Status](https://img.shields.io/travis/kapersoft/flysystem-sharefile/master.svg?style=flat-square)](https://travis-ci.org/kapersoft/flysystem-sharefile)
[![StyleCI](https://styleci.io/repos/000000/shield?branch=master)](https://styleci.io/repos/000000)
[![Quality Score](https://img.shields.io/scrutinizer/g/kapersoft/flysystem-sharefile.svg?style=flat-square)](https://scrutinizer-ci.com/g/kapersoft/flysystem-sharefile)
[![Total Downloads](https://img.shields.io/packagist/dt/kapersoft/flysystem-sharefile.svg?style=flat-square)](https://packagist.org/packages/kapersoft/flysystem-sharefile)

This package contains a [Flysystem](https://flysystem.thephpleague.com/) adapter for [Citrix ShareFile](https://www.sharefile.com). Under the hood my [Sharefile API package](https://github.com/kapersoft/sharefile-api) is used.

## Installation
You can install the package via composer:

``` bash
composer require kapersoft/flysystem-sharefile
```

## Usage
The first thing you need to do is get an OAuth2 key. Go to the [Get an API key](https://api.sharefile.com/rest/oauth2-request.aspx) section on the [ShareFile API site](https://api.sharefile.com/) to get this key.

With an OAuth2 key you can instantiate a `Kapersoft\Sharefile\Client` and setup a Flysystem adapter:
``` php
use League\Flysystem\Filesystem;
use Kapersoft\Sharefile\Client;
use Kapersoft\FlysystemSharefile\SharefileAdapter;

$client = new Client('hostname', 'client_id', 'secret', 'username', 'password');

$adapter = new SharefileAdapter($client);

$filesystem = new Filesystem($adapter);
```

## Changelog
Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Testing
In the `/tests`-folder are two tests defined
- `SharefileAdapterTest.php`
- `SharefileAdapterFunctionalTest.php`

To start both tests type in your terminal:
``` bash
composer test
```

`SharefileAdapterTest.php` tests the `Kapersoft\FlysystemSharefile\SharefileAdapter`-class using [phpspec prophecy](https://github.com/phpspec/prophecy) and mock objects.

`SharefileAdapterFunctionalTest.php` is a set of functional tests using an online ShareFile drive . To enable this test, fill in your ShareFile credentials under section `<PHP>` of the `phpunit.xml.dist`-file in the project root folder. 
Each test will create the folder named `Flysystem-sharefile-test` in your personal ShareFile drive for storing temporary test-files. When the test is completed, the `Flysystem-sharefile-test`-folder will be removed.
A [WebDav](https://github.com/fruux/sabre-dav) connection to your ShareFile drive is used to assert all tests. _**Note**: Make sure WebDav is enabled for your ShareFile account (see https://support.citrix.com/article/CTX207863 for more information)._

## Contributing
Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security
If you discover any security related issues, please email kapersoft@gmail.com instead of using the issue tracker.

## License
The MIT License (MIT). Please see [License File](LICENSE.txt) for more information.

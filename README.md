# A custom assertion for phpunit that allows you to count the amount of SQL queries used in a test. Can be used to enforce certain performance characteristics (ie: limit queries to X for a certain action).

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mattiasgeniar/phpunit-db-querycounter.svg?style=flat-square)](https://packagist.org/packages/mattiasgeniar/phpunit-db-querycounter)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/mattiasgeniar/phpunit-db-querycounter/run-tests?label=tests)](https://github.com/mattiasgeniar/phpunit-db-querycounter/actions?query=workflow%3Arun-tests+branch%3Amaster)
[![Total Downloads](https://img.shields.io/packagist/dt/mattiasgeniar/phpunit-db-querycounter.svg?style=flat-square)](https://packagist.org/packages/mattiasgeniar/phpunit-db-querycounter)


This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Support us

Learn how to create a package like this one, by watching our premium video course:

[![Laravel Package training](https://spatie.be/github/package-training.jpg)](https://laravelpackage.training)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require mattiasgeniar/phpunit-db-querycounter
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --provider="Mattiasgeniar\PhpunitDbQuerycounter\PhpunitDbQuerycounterServiceProvider" --tag="migrations"
php artisan migrate
```

You can publish the config file with:
```bash
php artisan vendor:publish --provider="Mattiasgeniar\PhpunitDbQuerycounter\PhpunitDbQuerycounterServiceProvider" --tag="config"
```

This is the contents of the published config file:

```php
return [
];
```

## Usage

``` php
$phpunit-db-querycounter = new Mattiasgeniar\PhpunitDbQuerycounter();
echo $phpunit-db-querycounter->echoPhrase('Hello, Mattiasgeniar!');
```

## Testing

``` bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Mattias Geniar](https://github.com/mattiasgeniar)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

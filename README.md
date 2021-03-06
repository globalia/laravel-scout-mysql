# Laravel Scout MySQL Driver

This package is a [MySQL](https://www.mysql.com/) driver for Laravel Scout.

## Contents

- [Installation](#installation)
- [Usage](#usage)
- [Credits](#credits)

## Installation

You can install the package via composer:

```bash
composer config repositories.globalia/laravel-scout-mysql git https://github.com/globalia/laravel-scout-mysql.git

composer require "globalia/laravel-scout-mysql" "^1.0"
```

You must add the Scout service provider and the package service provider in your app.php config:

```php
// config/app.php
'providers' => [
    ...
    Laravel\Scout\ScoutServiceProvider::class,
    Globalia\LaravelScoutMysql\ScoutMysqlServiceProvider::class,
],
```
### Setting up database search indexes table:

```php
php artisan migrate
```

After you've published the Laravel Scout package configuration:

```php
// config/scout.php
// Set your driver to mysql
    'driver' => env('SCOUT_DRIVER', 'mysql'),
```

## Usage

Here is an example of how to use the engine:

```php
    $result = Todo::search($term)
        ->where('boost(name)', 5)
        ->where('boost(tags)', 2)
        ->where('checked', 1)
        ->where('published_at >=', \Carbon::now());

    return null === $limit ? $result->get() : $result->paginate($limit);
```

Instead of using the "Laravel\Scout\Searchable" trait, use this "Globalia\LaravelScoutMysql\Models\Concerns\HasSearchIndex"

otherwise you can use Laravel Scout as described in the [official documentation](https://laravel.com/docs/5.4/scout)

### Searching in all models

Since the index table is a model, you can search in it directly. Each result will be hydrated to its corresponding model.

```php
    $result = SearchIndex::search($term);
```


## Credits

- [Globalia](https://github.com/globalia)
- [All Contributors](../../contributors)

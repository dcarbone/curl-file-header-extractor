# curl-file-header-extractor
Utility to extract headers from PHP CURL to file request.

# Installation

While it is advisable to use [Composer](https://getcomposer.org/), this lib is simple enough to be used without it.

Composer Require entry:
```json
{
    "dcarbone/curl-file-header-extractor": "1.0.*"
}
```

# Usage

There are 3 methods available:

### [getHeaderAndBodyFromFile](./src/CURLFileHeaderExtractor.php#L195)

This method accepts a single argument that may either be the full path to the file
or a file resource created with [fopen](http://php.net/manual/en/function.fopen.php) with at least
read permissions.

The response will be an array with the following structure:

```php
array(
    // array of headers,
    // string of body content
);
```

#### Example: 

```php
list($headers, $body) = \DCarbone\CURLFileHeaderExtractor::getHeaderAndBodyFromFile($file);
```

where `$headers` will be an array of headers, or NULL if no headers were found,
and `$body` will be the entire contents of the body.

#### Note:

This method CAN be very expensive to use if you are working with particularly large files, as the end
result will be the entire contents of the file loaded into memory.

If you wish to extract JUST the headers, the below methods might serve you better.

### [extractHeadersAndBodyStartOffset](./src/CURLFileHeaderExtractor.php#L38)

This method will return an array with the following structure:

```php
array(
    // array of headers,
    // integer representing the byte offset of the beginning of the body
);
```

#### Example: 

```php
list($headers, $bodyByteOffset) = \DCarbone\CURLFileHeaderExtractor::extractHeadersAndBodyStartOffset($file);
```

If no headers were seen in the file, `$headers` in the above example will be NULL and the byte offset
will be 0.

### [removeHeadersAndMoveFile](./src/CURLFileHeaderExtractor.php#L134)

This method will strip the file of the headers, copy the body to a new file, and then delete the old file.

#### Example:

```php
\DCarbone\CURLFileHeaderExtractor::removeHeadersAndMoveFile($file, 'my_new_filename.ext');
```

If you executed the [extractHeadersAndBodyStartOffset](./src/CURLFileHeaderExtractor.php#L38) method
already, you may pass in the body start offset integer in as the 3rd argument.

## Invoking

To make this class easier to work with as a "helper", it implements the 
[PHP magic method __invoke](http://php.net/manual/en/language.oop5.magic.php#object.invoke) (you
can see the implementation [here](./src/CURLFileHeaderExtractor.php#L24)).

This allows you to do something like this:

```php
$extractor = new \DCarbone\CURLFileHeaderExtractor();

list($headers, $body) = $extractor($file);
```

You can, of course, access the other methods as you normally would any static method:

```php
list($headers, $bodyByteOffset) = $extractor::extractHeadersAndBodyStartOffset($file);
```

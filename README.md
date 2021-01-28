# curl-header-extractor
Utility to extract headers from PHP CURL request.

# Installation

While it is advisable to use [Composer](https://getcomposer.org/), this lib is simple enough to be used without it.

Composer Require entry:
```json
{
    "dcarbone/curl-header-extractor": "v3.0.*"
}
```

# Usage

There are 3 methods available:

### [getHeaderAndBody](./src/CURLHeaderExtractor.php#L164)

This method accepts a single argument that may be:

- Full path to the file
- File resource created with [fopen](http://php.net/manual/en/function.fopen.php) with at least read permissions.
- String of response data

The response will be an array with the following structure:

```php
array(
    // array of headers,
    // string of body content
);
```

#### Example: 

```php
list($headers, $body) = \DCarbone\CURLHeaderExtractor::getHeaderAndBody($input);
```

where `$headers` will be an array of headers, or NULL if no headers were found,
and `$body` will be the entire contents of the body.

#### Note:

This method CAN be very expensive to use if you are working with particularly large responses, as the end
result will be the entire contents of the file loaded into memory.

If you wish to extract JUST the headers, the below methods might serve you better.

### [extractHeadersAndBodyStartOffset](./src/CURLHeaderExtractor.php#L77)

This method will return an array with the following structure:

```php
array(
    // array of headers,
    // integer representing the byte offset of the beginning of the body
);
```

#### Example: 

```php
list($headers, $bodyByteOffset) = \DCarbone\CURLHeaderExtractor::extractHeadersAndBodyStartOffset($input);
```

If no headers were seen in the file, `$headers` in the above example will be NULL and the byte offset
will be 0.

### [removeHeadersAndMoveFile](./src/CURLHeaderExtractor.php#L98)

This method will strip the file of the headers, copy the body to a new file, and then delete the old file.

#### Example:

```php
\DCarbone\CURLHeaderExtractor::removeHeadersAndMoveFile($file, 'my_new_filename.ext');
```

If you executed the [extractHeadersAndBodyStartOffset](./src/CURLHeaderExtractor.php#L77) method
already, you may pass in the body start offset integer in as the 3rd argument.

## Invoking

To make this class easier to work with as a "helper", it implements the 
[PHP magic method __invoke](http://php.net/manual/en/language.oop5.magic.php#object.invoke) (you
can see the implementation [here](./src/CURLHeaderExtractor.php#L63)).

This allows you to do something like this:

```php
$extractor = new \DCarbone\CURLHeaderExtractor();

list($headers, $body) = $extractor($input);
```

You can, of course, access the other methods as you normally would any static method:

```php
list($headers, $bodyByteOffset) = $extractor::extractHeadersAndBodyStartOffset($input);
```

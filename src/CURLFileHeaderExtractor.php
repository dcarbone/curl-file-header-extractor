<?php namespace DCarbone;

/*
    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
    SOFTWARE.
*/

/**
 * Class CURLFileHeaderExtractor
 * @package DCarbone
 * @author Daniel Carbone (daniel.p.carbone@gmail.com)
 */
class CURLFileHeaderExtractor
{
    /**
     * @param resource|string $file
     * @return array
     */
    function __invoke($file)
    {
        return static::getHeaderAndBodyFromFile($file);
    }

    /**
     * Returns array(
     *  array $headers,
     *  int $byteOffset
     * )
     *
     * @param string $file
     * @return array
     */
    public static function extractHeadersAndBodyStartOffset($file)
    {
        if (!file_exists($file))
        {
            throw new \InvalidArgumentException(sprintf(
                '%s::extractHeadersAndBodyStartOffset - Specified non existent file "%s".',
                get_called_class(),
                $file
            ));
        }

        $fh = fopen($file, 'rb');

        if ($fh)
        {
            $headers = array();

            // Default apache header size is 8KB, hopefully OK for ALL TIME  <( O_O )>
            $lineNum = 0;
            $rns = 0;
            $headerNum = 0;
            $possibleHeader = array();
            $innerHeaderLineCount = 0;
            $bodyStartByteOffset = 0;
            while (false !== ($line = fgets($fh, 8192)))
            {
                // If we do not have headers in the output...
                if (0 === $lineNum && 0 !== strpos($line, 'HTTP/1.'))
                {
                    $headers = null;
                    break;
                }

                // We...probably should just give up.
                if ($innerHeaderLineCount > 100)
                {
                    $headers = null;
                    break;
                }

                // If we hit here, we're probably done with parsing headers...
                if ($headerNum > 0 && $innerHeaderLineCount === 0 && $line !== "\r\n" && strpos($line, 'HTTP/1.') !== 0)
                    break;

                // Keep track of our current byte offset from start of file.
                $bodyStartByteOffset = ftell($fh);

                // Keep track of consecutive "\r\n" character pairs
                if (($rns === 0 && substr($line, -2) === "\r\n") || $line === "\r\n")
                    $rns++;

                // If we've reached 2, we should have successfully parsed a header.
                // Store as header, reset inner header line count, consecutive rn count, and
                // iterate noticed header count.
                if ($rns === 2)
                {
                    $headers[] = $possibleHeader;
                    $headerNum++;
                    $innerHeaderLineCount = 0;
                    $rns = 0;
                    continue;
                }

                // The first line in a header will not have a colon...
                if (strpos($line, ':') === false)
                {
                    $possibleHeader[] = trim($line);
                }
                else
                {
                    list ($header, $value) = explode(':', $line, 2);
                    $possibleHeader[trim($header)] = trim($value);
                }

                $lineNum++;
                $innerHeaderLineCount++;
            }

            fclose($fh);

            return array($headers, $bodyStartByteOffset);
        }

        throw new \RuntimeException(sprintf(
            '%s::extractHeadersAndBodyStartOffset - Unable to open file "%s" for reading.',
            get_called_class(),
            $file
        ));
    }

    /**
     * @param string $currentFile
     * @param string $targetFile
     * @param int $bodyStartByteOffset
     * @return string
     */
    public static function removeHeadersAndMoveFile($currentFile, $targetFile, $bodyStartByteOffset = null)
    {
        if (null === $bodyStartByteOffset)
            list ($headers, $bodyStartByteOffset) = static::extractHeadersAndBodyStartOffset($currentFile);

        $tfh = fopen($currentFile, 'rb');
        if ($tfh)
        {
             $fh = fopen($targetFile, 'w+b');

            if ($fh)
            {
                fseek($tfh, $bodyStartByteOffset);
                while (false === feof($tfh) && false !== ($data = fread($tfh, 8192)))
                {
                    fwrite($fh, $data);
                }

                fclose($tfh);
                fclose($fh);

                if (false === (bool)@unlink($currentFile))
                {
                    trigger_error(
                        sprintf(
                            '%s:removeHeadersAndMoveFile - Unable to remove temp file "%s"',
                            get_called_class(),
                            $currentFile
                        ),
                        E_USER_WARNING
                    );
                }

                return $targetFile;
            }

            throw new \RuntimeException(sprintf(
                '%s::removeHeadersAndMoveFile - Unable to open / create / truncate file at "%s".',
                get_called_class(),
                $targetFile
            ));
        }

        throw new \RuntimeException(sprintf(
            '%s::removeHeadersAndMoveFile - Unable to open temp file "%s".',
            get_called_class(),
            $currentFile
        ));
    }

    /**
     * Returns
     *
     * array(
     *  array $headers,
     *  string $body
     * )
     *
     * @param resource|string $file
     * @return array
     */
    public static function getHeaderAndBodyFromFile($file)
    {
        if (gettype($file) === 'resource')
        {
            $fh = $file;
            $closeHandle = false;
            $meta = stream_get_meta_data($file);
            if (isset($meta['uri']))
                $file = $meta['uri'];
            else
                throw new \RuntimeException('Unable to extract file location from passed in resource.');
        }
        else
        {
            $fh = fopen($file, 'rb');
            $closeHandle = true;
        }

        list ($headers, $byteOffset) = self::extractHeadersAndBodyStartOffset($file);

        // If no headers were seen in the file...
        if (null === $headers)
            return array(null, file_get_contents($file));

        if ($fh)
        {
            $body = '';

            rewind($fh);
            fseek($fh, $byteOffset);

            while (false === feof($fh) && false !== ($data = fread($fh, 8192)))
            {
                $body = sprintf('%s%s', $body, $data);
            }

            if ($closeHandle)
                fclose($fh);

            return array($headers, $body);
        }

        throw new \RuntimeException(sprintf(
            '%s::getHeaderAndBodyFromFile - Unable to open file "%s".',
            get_called_class(),
            $file
        ));
    }
}
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
class CURLHeaderExtractor
{
    const PROCESSING = 10;
    const DONE = 20;

    const PROCMODE_FILE = 100;
    const PROCMODE_STRING = 200;

    /** @var int */
    public static $isStringInputLengthLimit = 512;

    /** @var int */
    public static $maxHeaderLength = 8192;

    // Setup Vars

    /** @var string|resource */
    private static $_input = null;
    /** @var int  */
    private static $_mode;
    /** @var bool */
    private static $_closeHandle = true;
    /** @var null|resource */
    private static $_fh = null;

    // Parsing vars

    /** @var array */
    private static $_headers = array();
    /** @var int */
    private static $_lineNum = 0;
    /** @var int */
    private static $_rns = 0;
    /** @var int */
    private static $_headerNum = 0;
    /** @var array */
    private static $_possibleHeader = array();
    /** @var int */
    private static $_innerHeaderLineCount = 0;
    /** @var int */
    private static $_bodyStartByteOffset = 0;

    /**
     * @param resource|string $input
     * @return array
     */
    function __invoke($input)
    {
        return static::getHeaderAndBody($input);
    }

    /**
     * Returns array(
     *  array $headers,
     *  int $byteOffset
     * )
     *
     * @param string $input
     * @return array
     */
    public static function extractHeadersAndBodyStartOffset($input)
    {
        self::_setup($input);
        self::_process();

        return array(self::$_headers, self::$_bodyStartByteOffset);
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
     * @deprecated
     * @param resource|string $file
     * @return array
     */
    public static function getHeaderAndBodyFromFile($file)
    {
        return self::getHeaderAndBody($file);
    }

    /**
     * @param string|resource $input
     * @return array
     */
    public static function getHeaderAndBody($input)
    {
        list ($headers, $byteOffset) = self::extractHeadersAndBodyStartOffset($input);

        $body = '';

        if (self::PROCMODE_FILE === self::$_mode)
        {
            rewind(self::$_fh);
            fseek(self::$_fh, $byteOffset);

            while (false === feof(self::$_fh) && false !== ($data = fread(self::$_fh, 8192)))
            {
                $body = sprintf('%s%s', $body, $data);
            }

            return array($headers, $body);
        }

        if (self::PROCMODE_STRING === self::$_mode)
        {
            return array(
                $headers,
                mb_substr(self::$_input, self::$_bodyStartByteOffset)
            );

        }

        return array(null, null);
    }

    /**
     * Do stuff
     */
    private static function _process()
    {
        switch(self::$_mode)
        {
            case self::PROCMODE_FILE:
                self::_processFile();
                break;
            case self::PROCMODE_STRING:
                self::_processString();
                break;

            default:
                throw new \DomainException('CURLHeaderExtractor - Invalid state');
        }
    }

    private static function _processFile()
    {
        // Default apache header size is 8KB, hopefully OK for ALL TIME  <( O_O )>
        while (false !== ($line = fgets(self::$_fh, self::$maxHeaderLength)))
        {
            switch(self::_processLine($line))
            {
                case self::PROCESSING:
                    continue 2;
                case self::DONE:
                    return;
            }
        };
    }

    private static function _processString()
    {
        $line = '';
        $totalLength = mb_strlen(self::$_input);
        for ($i = 0; $i < $totalLength; )
        {
            // TODO: this could probably be improved...
            if (mb_strlen($line) > self::$maxHeaderLength && self::$_headerNum > 0)
                return;

            if (false === strpos($line, "\r\n"))
            {
                $line = sprintf('%s%s', $line, mb_substr(self::$_input, $i++, 1));
            }
            else
            {
                $state = self::_processLine($line);
                $line = '';
                switch($state)
                {
                    case self::PROCESSING:
                        continue 2;
                    case self::DONE:
                        return;
                }
            }
        }
    }

    /**
     * @param string $line
     * @return int
     */
    private static function _processLine($line)
    {
        // If we do not have headers in the output...
        if (0 === self::$_lineNum && 0 !== strpos($line, 'HTTP/1.'))
        {
            self::$_headers = null;
            return self::DONE;
        }

        // We...probably should just give up.
        if (self::$_innerHeaderLineCount > 100)
        {
            self::$_headers = null;
            return self::DONE;
        }

        // If we hit here, we're probably done with parsing headers...
        if (self::$_headerNum > 0
            && self::$_innerHeaderLineCount === 0
            && $line !== "\r\n"
            && strpos($line, 'HTTP/1.') !== 0)
        {
            return self::DONE;
        }

        // Keep track of our current byte offset
        self::$_bodyStartByteOffset += mb_strlen($line);

        // Keep track of consecutive "\r\n" character pairs
        if ((self::$_rns === 0 && substr($line, -2) === "\r\n") || $line === "\r\n")
            self::$_rns++;

        // If we've reached 2, we should have successfully parsed a header.
        // Store as header, reset inner header line count, consecutive rn count, and
        // iterate noticed header count.
        if (2 === self::$_rns)
        {
            self::$_headers[] = self::$_possibleHeader;
            self::$_headerNum++;
            self::$_innerHeaderLineCount = 0;
            self::$_rns = 0;
            return self::PROCESSING;
        }

        // The first line in a header will not have a colon...
        if (strpos($line, ':') === false)
        {
            self::$_possibleHeader[] = trim($line);
        }
        else
        {
            list ($header, $value) = explode(':', $line, 2);
            self::$_possibleHeader[trim($header)] = trim($value);
        }

        self::$_lineNum++;
        self::$_innerHeaderLineCount++;

        return self::PROCESSING;
    }

    /**
     * @param string|resource $input
     * @throws \InvalidArgumentException
     */
    private static function _setup($input)
    {
        self::_reset();

        if ('resource' === gettype($input))
        {
            $meta = stream_get_meta_data($input);
            if (isset($meta['uri']))
            {
                self::$_mode = self::PROCMODE_FILE;
                self::$_input = $meta['uri'];
                self::$_closeHandle = false;
                self::$_fh = $input;
            }

            throw new \InvalidArgumentException('CURLHeaderExtractor - Could not extract filepath from resource.');
        }
        else if (is_string($input))
        {
            if (self::$isStringInputLengthLimit <= mb_strlen($input))
            {
                self::$_input = $input;
                self::$_mode = self::PROCMODE_STRING;
            }
            else if (is_file($input))
            {
                self::$_mode = self::PROCMODE_FILE;
                self::$_input = $input;
                self::$_closeHandle = true;
                self::$_fh = fopen($input, 'rb');
                if (false === self::$_fh)
                {
                    throw new \RuntimeException(sprintf(
                        'CURLHeaderExtractor - Unable to open file %s for reading.',
                        $input
                    ));
                }
            }
        }
        else
        {
            throw new \InvalidArgumentException(sprintf(
                'CURLHeaderExtractor - Invalid input seen. Expected file resource with read permissions, string filepath, or string curl response value.  %s seen.',
                gettype($input)
            ));
        }
    }

    /**
     * Resets internal parameters
     */
    private static function _reset()
    {
        if (self::$_mode === self::PROCMODE_FILE && self::$_closeHandle && 'resource' === gettype(self::$_fh))
            fclose(self::$_fh);

        self::$_input = null;
        self::$_mode = null;
        self::$_closeHandle = true;
        self::$_fh = null;

        self::$_headers = array();
        self::$_lineNum = 0;
        self::$_rns = 0;
        self::$_headerNum = 0;
        self::$_possibleHeader = array();
        self::$_innerHeaderLineCount = 0;
        self::$_bodyStartByteOffset = 0;
    }
}
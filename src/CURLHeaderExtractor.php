<?php declare(strict_types=1);

namespace DCarbone;

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
 * Class CURLHeaderExtractor
 * @package DCarbone
 * @author Daniel Carbone (daniel.p.carbone@gmail.com)
 */
class CURLHeaderExtractor
{
    private const HTTP_START = 'HTTP/';
    private const RN         = "\r\n";
    private const COLON      = ':';

    private const PROCESSING = 10;
    private const DONE       = 20;

    private const PROCMODE_FILE   = 100;
    private const PROCMODE_STRING = 200;

    /** @var int */
    public static int $maxHeaderLength = 8192;

    // Setup Vars

    /** @var string|resource */
    private static $_input = null;
    /** @var int */
    private static int $_mode = 0;
    /** @var bool */
    private static bool $_closeHandle = true;
    /** @var null|resource */
    private static $_fh = null;

    // Parsing vars

    /** @var array */
    private static array $_headers = [];
    /** @var int */
    private static int $_lineNum = 0;
    /** @var int */
    private static int $_rns = 0;
    /** @var int */
    private static int $_headerNum = 0;
    /** @var array */
    private static array $_possibleHeader = [];
    /** @var int */
    private static int $_innerHeaderLineCount = 0;
    /** @var int */
    private static int $_bodyStartByteOffset = 0;

    /**
     * @param resource|string $input
     * @return array
     */
    function __invoke($input): array
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
    public static function extractHeadersAndBodyStartOffset(string $input): array
    {
        self::_setup($input);

        if (self::PROCMODE_FILE === self::$_mode) {
            self::_processFile();
        } elseif (self::PROCMODE_STRING === self::$_mode) {
            self::_processString();
        } else {
            throw new \DomainException('CURLHeaderExtractor - Invalid state');
        }

        return [self::$_headers, self::$_bodyStartByteOffset];
    }

    /**
     * @param string $currentFile
     * @param string $targetFile
     * @param int|null $bodyStartByteOffset
     * @return string
     */
    public static function removeHeadersAndMoveFile(
        string $currentFile,
        string $targetFile,
        ?int $bodyStartByteOffset = null
    ): string {
        if (null === $bodyStartByteOffset) {
            [$_, $bodyStartByteOffset] = static::extractHeadersAndBodyStartOffset($currentFile);
        }

        $tfh = fopen($currentFile, 'rb');
        if ($tfh) {
            $fh = fopen($targetFile, 'w+b');

            if ($fh) {
                fseek($tfh, $bodyStartByteOffset);
                while (false === feof($tfh) && false !== ($data = fread($tfh, 8192))) {
                    fwrite($fh, $data);
                }

                fclose($tfh);
                fclose($fh);

                if (false === (bool)@unlink($currentFile)) {
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

            throw new \RuntimeException(
                sprintf(
                    '%s::removeHeadersAndMoveFile - Unable to open / create / truncate file at "%s".',
                    get_called_class(),
                    $targetFile
                )
            );
        }

        throw new \RuntimeException(
            sprintf(
                '%s::removeHeadersAndMoveFile - Unable to open temp file "%s".',
                get_called_class(),
                $currentFile
            )
        );
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
     * @deprecated
     */
    public static function getHeaderAndBodyFromFile($file): array
    {
        return self::getHeaderAndBody($file);
    }

    /**
     * @param string|resource $input
     * @return array
     */
    public static function getHeaderAndBody($input): array
    {
        [$headers, $byteOffset] = self::extractHeadersAndBodyStartOffset($input);

        switch (self::$_mode) {
            case self::PROCMODE_FILE:
                $body = '';

                rewind(self::$_fh);
                fseek(self::$_fh, $byteOffset);

                while (false === feof(self::$_fh) && false !== ($data = fread(self::$_fh, 8192))) {
                    $body = sprintf('%s%s', $body, $data);
                }

                return [$headers, $body];

            case self::PROCMODE_STRING:
                return [
                    $headers,
                    substr(self::$_input, self::$_bodyStartByteOffset),
                ];

            default:
                return [null, null];
        }
    }

    private static function _processFile(): void
    {
        rewind(self::$_fh);

        // Default apache header size is 8KB, hopefully OK for ALL TIME  <( O_O )>
        while (false !== ($line = fgets(self::$_fh, self::$maxHeaderLength))) {
            switch (self::_processLine($line)) {
                case self::PROCESSING:
                    continue 2;
                case self::DONE:
                    return;
            }
        };
    }

    private static function _processString(): void
    {
        $strPos = 0;
        $strlen = strlen(self::$_input);
        $rnPos = strpos(self::$_input, "\r\n");

        // If the first "\r\n" is beyond the possible header length limit, just move on.
        if (false === $rnPos || $rnPos > self::$maxHeaderLength) {
            return;
        }

        while ($strPos < $strlen && $strPos <= $rnPos) {
            $state = self::_processLine(substr(self::$_input, $strPos, ($rnPos - $strPos) + 2));
            switch ($state) {
                case self::PROCESSING:
                    $strPos = $rnPos + 2;
                    $rnPos = strpos(self::$_input, "\r\n", $strPos);
                    continue 2;
                case self::DONE:
                    return;
            }
        }
    }

    /**
     * @param string $line
     * @return int
     */
    private static function _processLine(string $line): int
    {
        $httpPos = strpos($line, self::HTTP_START);

        // If we do not have headers in the output...
        if (0 === self::$_lineNum && 0 !== $httpPos) {
            self::$_headers = [];
            return self::DONE;
        }

        // We...probably should just give up.
        if (self::$_innerHeaderLineCount > 100) {
            self::$_headers = [];
            return self::DONE;
        }

        // If we hit here, we're probably done with parsing headers...
        if (self::$_headerNum > 0
            && self::$_innerHeaderLineCount === 0
            && $line !== "\r\n"
            && 0 !== $httpPos) {
            return self::DONE;
        }

        // Keep track of our current byte offset
        self::$_bodyStartByteOffset += strlen($line);

        // Keep track of consecutive "\r\n" character pairs
        // tests for "\r\n" and ".....\r\n"
        if (self::RN === $line || (0 === self::$_rns && self::RN === substr($line, -2))) {
            self::$_rns++;
        }

        // If we've reached 2, we should have successfully parsed a header.
        // Store as header, reset inner header line count, consecutive rn count, and iterate noticed header count.
        if (2 === self::$_rns) {
            self::$_headers[] = self::$_possibleHeader;
            self::$_headerNum++;
            self::$_innerHeaderLineCount = 0;
            self::$_rns = 0;
            return self::PROCESSING;
        }

        // The first line in a header will not have a colon...
        $colonPos = strpos($line, self::COLON);
        if (false === $colonPos) {
            // this is a be an "HTTP/1.1 {code} {status}\r\n" line
            self::$_possibleHeader[] = trim($line);
        } else {
            // this is a "{header}: {value}\r\n" line
            $key = substr($line, 0, $colonPos);
            if (!isset(self::$_possibleHeader[$key])) {
                self::$_possibleHeader[$key] = [];
            }
            self::$_possibleHeader[$key][] = trim(substr($line, $colonPos + 1));
        }

        self::$_lineNum++;
        self::$_innerHeaderLineCount++;

        return self::PROCESSING;
    }

    /**
     * @param string|resource $input
     * @throws \InvalidArgumentException
     */
    private static function _setup($input): void
    {
        self::_reset();

        $inputType = gettype($input);

        if ('resource' === $inputType) {
            $meta = stream_get_meta_data($input);
            if (isset($meta['uri'])) {
                self::$_mode = self::PROCMODE_FILE;
                self::$_input = $meta['uri'];
                self::$_closeHandle = false;
                self::$_fh = $input;
            } else {
                throw new \InvalidArgumentException('CURLHeaderExtractor - Could not extract filepath from resource.');
            }
        } elseif ('string' === $inputType) {
            if (@is_file($input)) {
                self::$_mode = self::PROCMODE_FILE;
                self::$_input = $input;
                self::$_closeHandle = true;
                self::$_fh = fopen($input, 'rb');
                if (false === self::$_fh) {
                    throw new \RuntimeException(
                        sprintf(
                            'CURLHeaderExtractor - Unable to open file %s for reading.',
                            $input
                        )
                    );
                }
            } else {
                self::$_input = $input;
                self::$_mode = self::PROCMODE_STRING;
            }
        } else {
            throw new \InvalidArgumentException(
                sprintf(
                    'CURLHeaderExtractor - Invalid input seen. Expected file resource with read permissions, string filepath, or string curl response value.  %s seen.',
                    $inputType
                )
            );
        }
    }

    /**
     * Resets internal parameters
     */
    private static function _reset()
    {
        if (self::PROCMODE_FILE === self::$_mode && self::$_closeHandle && 'resource' === gettype(self::$_fh)) {
            fclose(self::$_fh);
        }

        self::$_input = null;
        self::$_mode = 0;
        self::$_closeHandle = true;
        self::$_fh = null;

        self::$_headers = [];
        self::$_lineNum = 0;
        self::$_rns = 0;
        self::$_headerNum = 0;
        self::$_possibleHeader = [];
        self::$_innerHeaderLineCount = 0;
        self::$_bodyStartByteOffset = 0;
    }
}

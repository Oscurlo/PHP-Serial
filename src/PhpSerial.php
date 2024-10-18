<?php

declare(strict_types=1);

namespace Hyperthese\PhpSerial;

use Exception;

/**
 * Serial port control class
 *
 * THIS PROGRAM COMES WITH ABSOLUTELY NO WARRANTIES !
 * USE IT AT YOUR OWN RISKS !
 *
 * @author Rémy Sanchez <remy.sanchez@hyperthese.net>
 * @author Rizwan Kassim <rizwank@geekymedia.com>
 * @thanks Aurélien Derouineau for finding how to open serial ports with windows
 * @thanks Alec Avedisyan for help and testing with reading
 * @thanks Jim Wright for OSX cleanup/fixes.
 * @copyright under GPL 2 licence
 */
class PhpSerial
{
    const SERIAL_DEVICE_NOTSET = 0;
    const SERIAL_DEVICE_SET = 1;
    const SERIAL_DEVICE_OPENED = 2;


    public ?string $_device = null;
    public ?string $_winDevice = null;
    public $_dHandle;
    public int $_dState = self::SERIAL_DEVICE_NOTSET;
    public string $_buffer = "";
    public string $_os = "";

    /**
     * This var says if buffer should be flushed by sendMessage (true) or
     * manually (false)
     *
     * @var bool
     */
    public bool $autoFlush = true;

    /**
     * Constructor. Perform some checks about the OS and set serial
     *
     * @return void
     * @throws Exception
     */
    public function __construct()
    {
        setlocale(LC_ALL, "en_US");

        $sysName = php_uname();

        switch (true) {
            case str_starts_with($sysName, "Linux"):
                $this->_os = "linux";
                break;
            case str_starts_with($sysName, "Darwin"):
                $this->_os = "osx";
                break;
            case str_starts_with($sysName, "Windows"):
                $this->_os = "windows";
                break;
            default:
                throw new Exception("Unsupported OS: {$sysName}", E_USER_ERROR);
        }
    }

    //
    // OPEN/CLOSE DEVICE SECTION -- {START}
    //

    /**
     * Device set function : used to set the device name/address.
     * -> linux : use the device address, like /dev/ttyS0
     * -> osx : use the device address, like /dev/tty.serial
     * -> windows : use the COMxx device name, like COM1 (can also be used
     *   with linux)
     *
     * @param string $device the name of the device to be used
     * @return bool
     * @throws Exception
     */
    public function deviceSet(string $device): bool
    {
        if ($this->_dState !== self::SERIAL_DEVICE_OPENED) {
            if ($this->_os === "linux") {
                if (preg_match("@^COM(\\d+):?$@i", $device, $matches)) {
                    $device = "/dev/ttyS" . ($matches[1] - 1);
                }

                if ($this->_exec("stty -F " . escapeshellcmd($device)) === 0) {
                    $this->_device = $device;
                    $this->_dState = self::SERIAL_DEVICE_SET;

                    return true;
                }
            } elseif ($this->_os === "osx") {
                if ($this->_exec("stty -f " . escapeshellcmd($device))  === 0) {
                    $this->_device = $device;
                    $this->_dState = self::SERIAL_DEVICE_SET;

                    return true;
                }
            } elseif ($this->_os === "windows") {
                if (
                    preg_match("@^COM(\\d+):?$@i", $device, $matches)
                    and $this->_exec(
                        exec("mode {$device} xon=on BAUD=9600")
                    ) === 0
                ) {
                    $this->_winDevice = "COM{$matches[1]}";
                    $this->_device = "\\.com{$matches[1]}";
                    $this->_dState = self::SERIAL_DEVICE_SET;

                    return true;
                }
            }

            throw new Exception("Specified serial port is not valid", E_USER_WARNING);
        } else {
            throw new Exception("You must close your device before to set an other one", E_USER_WARNING);
        }
    }

    /**
     * Opens the device for reading and/or writing.
     *
     * @param string $mode Opening mode : same parameter as open()
     * @return bool
     * @throws Exception
     */
    public function deviceOpen(string $mode = "r+b"): bool
    {
        if ($this->_dState === self::SERIAL_DEVICE_OPENED) throw new Exception(
            "The device is already opened",
            E_USER_NOTICE
        );

        else if ($this->_dState === self::SERIAL_DEVICE_NOTSET) throw new Exception(
            "The device must be set before to be open",
            E_USER_WARNING
        );

        else if (!preg_match("@^[raw]\\+?b?$@", $mode)) throw new Exception(
            "Invalid opening mode : {$mode}. Use open() modes.",
            E_USER_WARNING
        );

        $this->_dHandle = fopen($this->_device, $mode);

        if (!$this->_dHandle) {
            throw new Exception("Failed to open the device.");
        }


        stream_set_blocking($this->_dHandle, false);
        $this->_dState = self::SERIAL_DEVICE_OPENED;

        return true;

        // $this->_dHandle = null;
        // throw new Exception("Unable to open the device", E_USER_WARNING);
    }

    /**
     * Closes the device
     *
     * @return bool
     * @throws Exception
     */
    public function deviceClose(): bool
    {
        if ($this->_dState !== self::SERIAL_DEVICE_OPENED) {
            return true;
        }

        if (fclose($this->_dHandle)) {
            $this->_dHandle = false;
            $this->_dState = self::SERIAL_DEVICE_SET;

            return true;
        }

        throw new Exception("Unable to close the device", E_USER_ERROR);
    }

    //
    // OPEN/CLOSE DEVICE SECTION -- {STOP}
    //

    //
    // CONFIGURE SECTION -- {START}
    //

    /**
     * Configure the Baud Rate
     * Possible rates : 110, 150, 300, 600, 1200, 2400, 4800, 9600, 38400,
     * 57600 and 115200.
     *
     * @param int $rate the rate to set the port in
     * @return bool
     * @throws Exception
     */
    public function confBaudRate(int $rate): bool
    {
        if ($this->_dState !== self::SERIAL_DEVICE_SET) throw new Exception(
            "Unable to set the baud rate : the device is either not set or opened",
            E_USER_WARNING
        );

        $validBauds = [
            110 => 11,
            150 => 15,
            300 => 30,
            600 => 60,
            1200 => 12,
            2400 => 24,
            4800 => 48,
            9600 => 96,
            19200 => 19,
            38400 => 38400,
            57600 => 57600,
            115200 => 115200
        ];

        if (isset($validBauds[$rate])) {
            if ($this->_os === "linux") {
                $ret = $this->_exec(
                    "stty -F {$this->_device} {$rate}",
                    $out
                );
            } elseif ($this->_os === "osx") {
                $ret = $this->_exec(
                    "stty -f {$this->_device} {$rate}",
                    $out
                );
            } elseif ($this->_os === "windows") {
                $ret = $this->_exec(
                    "mode {$this->_winDevice} BAUD={$validBauds[$rate]}",
                    $out
                );
            } else {
                return false;
            }

            if ($ret !== 0) throw new Exception(
                "Unable to set baud rate: {$out[1]}",
                E_USER_WARNING
            );

            return true;
        } else {
            return false;
        }
    }

    /**
     * Configure parity.
     * Modes : odd, even, none
     *
     * @param string $parity one of the modes
     * @return bool
     * @throws Exception
     */
    public function confParity(string $parity): bool
    {
        if ($this->_dState !== self::SERIAL_DEVICE_SET) throw new Exception(
            "Unable to set parity : the device is either not set or opened",
            E_USER_WARNING
        );

        $args = [
            "none" => "-parenb",
            "odd" => "parenb parodd",
            "even" => "parenb -parodd"
        ];

        if (!isset($args[$parity])) throw new Exception(
            "Parity mode not supported",
            E_USER_WARNING
        );

        if ($this->_os === "linux") {
            $ret = $this->_exec(
                "stty -F {$this->_device} {$args[$parity]}",
                $out
            );
        } elseif ($this->_os === "osx") {
            $ret = $this->_exec(
                "stty -f {$this->_device} {$args[$parity]}",
                $out
            );
        } else {
            $ret = $this->_exec(
                "mode {$this->_winDevice} PARITY={$parity[0]}",
                $out
            );
        }

        if ($ret === 0) {
            return true;
        }

        throw new Exception("Unable to set parity : {$out[1]}", E_USER_WARNING);
    }

    /**
     * Sets the length of a character.
     *
     * @param int $int length of a character (5 <= length <= 8)
     * @return bool
     * @throws Exception
     */
    public function confCharacterLength(int $int): bool
    {
        if ($this->_dState !== self::SERIAL_DEVICE_SET) throw new Exception(
            "Unable to set length of a character : the device is either not set or opened",
            E_USER_WARNING
        );

        $int = $int < 5 ? 5 : 8;

        if ($this->_os === "linux") {
            $ret = $this->_exec(
                "stty -F {$this->_device} cs{$int}",
                $out
            );
        } elseif ($this->_os === "osx") {
            $ret = $this->_exec(
                "stty -f {$this->_device} cs{$int}",
                $out
            );
        } else {
            $ret = $this->_exec(
                "mode {$this->_winDevice} DATA={$int}",
                $out
            );
        }

        if ($ret === 0) return true;

        throw new Exception(
            "Unable to set character length : {$out[1]}",
            E_USER_WARNING
        );
    }

    /**
     * Sets the length of stop bits.
     *
     * @param float $length the length of a stop bit. It must be either 1,
     *            1.5 or 2. 1.5 is not supported under linux and on
     *            some computers.
     * @return bool
     * @throws Exception
     */
    public function confStopBits(float $length): bool
    {
        if ($this->_dState !== self::SERIAL_DEVICE_SET) throw new Exception(
            "Unable to set the length of a stop bit : the device is either not set or opened",
            E_USER_WARNING
        );

        if (
            $length != 1
            and $length != 2
            and $length != 1.5
            and !($length == 1.5 and $this->_os === "linux")
        ) throw new Exception(
            "Specified stop bit length is invalid",
            E_USER_WARNING
        );

        if ($this->_os === "linux") {
            $ret = $this->_exec(
                "stty -F {$this->_device} " .
                    (($length == 1) ? "-" : "") . "cstopb",
                $out
            );
        } elseif ($this->_os === "osx") {
            $ret = $this->_exec(
                "stty -f {$this->_device} " .
                    (($length == 1) ? "-" : "") . "cstopb",
                $out
            );
        } else {
            $ret = $this->_exec(
                "mode {$this->_winDevice} STOP={$length}",
                $out
            );
        }

        if ($ret === 0) {
            return true;
        }

        throw new Exception(
            "Unable to set stop bit length : {$out[1]}",
            E_USER_WARNING
        );
    }

    /**
     * Configures the flow control
     *
     * @param string $mode Set the flow control mode. Available modes :
     *           -> "none" : no flow control
     *           -> "rts/cts" : use RTS/CTS handshaking
     *           -> "xon/xoff" : use XON/XOFF protocol
     * @return bool
     * @throws Exception
     */
    public function confFlowControl(string $mode): bool
    {
        if ($this->_dState !== self::SERIAL_DEVICE_SET) throw new Exception(
            "Unable to set flow control mode : the device is either not set or opened",
            E_USER_WARNING
        );

        $linuxModes = [
            "none" => "clocal -crtscts -ixon -ixoff",
            "rts/cts" => "-clocal crtscts -ixon -ixoff",
            "xon/xoff" => "-clocal -crtscts ixon ixoff"
        ];

        $windowsModes = [
            "none" => "xon=off octs=off rts=on",
            "rts/cts" => "xon=off octs=on rts=hs",
            "xon/xoff" => "xon=on octs=off rts=on"
        ];

        if ($mode !== "none" and $mode !== "rts/cts" and $mode !== "xon/xoff") throw new Exception(
            "Invalid flow control mode specified",
            E_USER_ERROR
        );

        if ($this->_os === "linux") {
            $ret = $this->_exec(
                "stty -F {$this->_device} {$linuxModes[$mode]}",
                $out
            );
        } elseif ($this->_os === "osx") {
            $ret = $this->_exec(
                "stty -f {$this->_device} {$linuxModes[$mode]}",
                $out
            );
        } else {
            $ret = $this->_exec(
                "mode {$this->_winDevice} {$windowsModes[$mode]}",
                $out
            );
        }

        if ($ret === 0) {
            return true;
        } else throw new Exception(
            "Unable to set flow control : {$out[1]}",
            E_USER_ERROR
        );
    }

    //
    // CONFIGURE SECTION -- {STOP}
    //

    //
    // I/O SECTION -- {START}
    //

    /**
     * Sends a string to the device
     *
     * @param string $str string to be sent to the device
     * @param float $waitForReply time to wait for the reply (in seconds)
     * @throws Exception
     */
    public function sendMessage(string $str, float $waitForReply = 0.1): void
    {
        $this->_buffer .= $str;

        if ($this->autoFlush === true) $this->serialflush();

        usleep((int) ($waitForReply * 1000000));
    }

    /**
     * Reads the port until no new data are available, then return the content.
     *
     * @param int $count Number of characters to be read (will stop before
     *          if less characters are in the buffer)
     * @return null|string
     * @throws Exception
     */
    public function readPort(int $count = 0): ?string
    {
        if ($this->_dState !== self::SERIAL_DEVICE_OPENED) throw new Exception(
            "Device must be opened to read it",
            E_USER_WARNING
        );

        if ($this->_os === "linux" || $this->_os === "osx") {
            // Behavior in OSX isn't to wait for new data to recover, but just
            // grabs what's there!
            // Doesn't always work perfectly for me in OSX
            return $this->readContent($count);
        } elseif ($this->_os === "windows") {
            // Windows port reading procedures still buggy
            return $this->readContent($count);
        }

        return null;
    }

    /**
     * @param int $count
     * @return string
     */
    private function readContent(int $count): string
    {
        $content = "";
        $i = 0;

        if ($count !== 0) {
            do {
                $content .= fread($this->_dHandle, $i > $count ? $count - $i : 128);
            } while (($i += 128) === strlen($content));
        } else {
            do {
                $content .= fread($this->_dHandle, 128);
            } while (($i += 128) === strlen($content));
        }

        return $content;
    }

    /**
     * Flushes the output buffer
     * Renamed from flush for osx compat. issues
     *
     * @return bool
     * @throws Exception
     */
    public function serialflush(): bool
    {
        if (!$this->_ckOpened()) return false;

        if (fwrite($this->_dHandle, $this->_buffer) !== false) {
            $this->_buffer = "";

            return true;
        } else {
            $this->_buffer = "";
            throw new Exception("Error while sending message", E_USER_WARNING);
        }
    }

    //
    // I/O SECTION -- {STOP}
    //

    //
    // INTERNAL TOOLKIT -- {START}
    //

    /**
     * @throws Exception
     */
    public function _ckOpened(): bool
    {
        if ($this->_dState !== self::SERIAL_DEVICE_OPENED) throw new Exception(
            "Device must be opened",
            E_USER_WARNING
        );

        return true;
    }

    /**
     * @throws Exception
     */
    public function _ckClosed(): bool
    {
        if ($this->_dState === self::SERIAL_DEVICE_OPENED) throw new Exception(
            "Device must be closed",
            E_USER_WARNING
        );

        return true;
    }

    /**
     * @param array|string $cmd
     * @param mixed|null $out
     * @return int
     */
    public function _exec(array|string $cmd, mixed &$out = null): int
    {
        $desc = [
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];

        $proc = proc_open($cmd, $desc, $pipes);

        $ret = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $retVal = proc_close($proc);

        if (func_num_args() == 2) $out = [$ret, $err];

        return $retVal;
    }

    //
    // INTERNAL TOOLKIT -- {STOP}
    //


}

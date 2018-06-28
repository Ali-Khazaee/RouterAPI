<?php
    // Inspired from: https://github.com/BenMenking/routeros-api, http://www.mikrotik.com, http://wiki.mikrotik.com/wiki/API_PHP_class

    class RouterAPI
    {
        private $Socket;
        private $IsConnected = false;

        public $IsDebug = false;
        public $IsSSL = false;
        public $Attempt = 3;
        public $Timeout = 5;
        public $Delay = 3;

        public function Connect($Username, $Password, $Host, $Port = 8728)
        {
            for ($I = 1; $I <= $this->Attempt; $I++)
            {
                $this->IsConnected = false;
                $Protocol = $this->IsSSL ? 'ssl://' : '';

                $this->Debug('>> Connection Attempt #' . $I . ' To ' . $Protocol . $Host . ':' . $Port);

                $Context = stream_context_create(array('ssl' => array('ciphers' => 'ADH:ALL', 'verify_peer' => false, 'verify_peer_name' => false)));
                $this->Socket = @stream_socket_client($Protocol . $Host . ':' . $Port, $ErrorNumber, $ErrorMessage, $this->Timeout, STREAM_CLIENT_CONNECT, $Context);
                
                if ($this->Socket)
                {
                    socket_set_timeout($this->Socket, $this->Timeout);
                    $this->Write('/login');
                    $Result = $this->Read(false);

                    if (isset($Result[0]) && $Result[0] == '!done')
                    {
                        $Found = array();
                        
                        if (preg_match_all('/[^=]+/i', $Result[1], $Found))
                        {
                            if ($Found[0][0] == 'ret' && strlen($Found[0][1]) == 32)
                            {
                                $this->Write('/login', false);
                                $this->Write('=name=' . $Username, false);
                                $this->Write('=response=00' . md5(chr(0) . $Password . pack('H*', $Found[0][1])));
                                $Result = $this->Read(false);
                                
                                if (isset($Result[0]) && $Result[0] == '!done')
                                {
                                    $this->Debug('>> Connected');
                                    $this->IsConnected = true;
                                    break;
                                }
                            }
                        }
                    }
                    
                    fclose($this->Socket);
                }
                
                sleep($this->Delay);
            }

            return $this->IsConnected;
        }

        private function Debug($Message)
        {
            if ($this->IsDebug)
                echo '<pre>' . var_export($Message, true) . '</pre>';
        }

        public function Disconnect()
        {
            if (is_resource($this->Socket))
                fclose($this->Socket);

            $this->IsConnected = false;
            $this->Debug('>> Disconnected');
        }

        public function Write($Commands, $Type = true)
        {
            if ($Commands)
            {
                $Data = explode("\n", $Commands);

                foreach ($Data as $Command)
                {
                    $Command = trim($Command);
                    fwrite($this->Socket, $this->EncodeLength(strlen($Command)) . $Command);
                    $this->Debug('>> Send: String[' . strlen($Command) . '] ' . $Command);
                }

                if (gettype($Type) == 'integer')
                {
                    fwrite($this->Socket, $this->EncodeLength(strlen('.tag=' . $Type)) . '.tag=' . $Type . chr(0));
                    $this->Debug('>> Send: Tag[' . strlen('.tag=' . $Type) . '] ' . $Type);
                }
                elseif (gettype($Type) == 'boolean')
                {
                    fwrite($this->Socket, ($Type ? chr(0) : ''));
                    $this->Debug('>> Send:' . ($Type ? chr(0) : ''));
                }
            }
        }
        
        private function EncodeLength($Length)
        {
            if ($Length < 0x80)
            {
                $Length = chr($Length);
            }
            elseif ($Length < 0x4000)
            {
                $Length |= 0x8000;
                $Length = chr(($Length >> 8) & 0xFF) . chr($Length & 0xFF);
            }
            elseif ($Length < 0x200000)
            {
                $Length |= 0xC00000;
                $Length = chr(($Length >> 16) & 0xFF) . chr(($Length >> 8) & 0xFF) . chr($Length & 0xFF);
            }
            elseif ($Length < 0x10000000)
            {
                $Length |= 0xE0000000;
                $Length = chr(($Length >> 24) & 0xFF) . chr(($Length >> 16) & 0xFF) . chr(($Length >> 8) & 0xFF) . chr($Length & 0xFF);
            }
            elseif ($Length >= 0x10000000)
            {
                $Length = chr(0xF0) . chr(($Length >> 24) & 0xFF) . chr(($Length >> 16) & 0xFF) . chr(($Length >> 8) & 0xFF) . chr($Length & 0xFF);
            }

            return $Length;
        }
        
        public function Command($Command, $Query = array())
        {
            $Count = count($Query);
            $this->Write($Command, !$Query);
            $I = 0;

            if ($this->IsIterable($Query))
            {
                foreach ($Query as $Key => $Value)
                {
                    switch ($Key[0])
                    {
                        case "?":
                            $KeyValue = "$Key=$Value";
                            break;
                        case "~":
                            $KeyValue = "$Key~$Value";
                            break;
                        default:
                            $KeyValue = "=$Key=$Value";
                            break;
                    }

                    $this->Write($KeyValue, ($I++ == $Count - 1));
                }
            }

            return $this->Read();
        }

        private function IsIterable($Variable)
        {
            return $Variable !== null && (is_array($Variable) || $Variable instanceof Traversable || $Variable instanceof Iterator || $Variable instanceof IteratorAggregate);
        }
        
        public function Read($Parse = true)
        {
            $IsDone = false;
            $Result = array();

            while (true)
            {
                $Length = 0;
                $Data = ord(fread($this->Socket, 1));

                if ($Data & 128)
                {
                    if (($Data & 192) == 128)
                        $Length = (($Data & 63) << 8) + ord(fread($this->Socket, 1));
                    else
                    {
                        if (($Data & 224) == 192) {
                            $Length = (($Data & 31) << 8) + ord(fread($this->Socket, 1));
                            $Length = ($Length << 8) + ord(fread($this->Socket, 1));
                        }
                        else
                        {
                            if (($Data & 240) == 224)
                            {
                                $Length = (($Data & 15) << 8) + ord(fread($this->Socket, 1));
                                $Length = ($Length << 8) + ord(fread($this->Socket, 1));
                                $Length = ($Length << 8) + ord(fread($this->Socket, 1));
                            }
                            else
                            {
                                $Length = ord(fread($this->Socket, 1));
                                $Length = ($Length << 8) + ord(fread($this->Socket, 1));
                                $Length = ($Length << 8) + ord(fread($this->Socket, 1));
                                $Length = ($Length << 8) + ord(fread($this->Socket, 1));
                            }
                        }
                    }
                }
                else
                    $Length = $Data;

                $Holder = "";

                if ($Length > 0)
                {
                    $Holder = "";
                    $ReturnLength = 0;

                    while ($ReturnLength < $Length)
                    {
                        $ToRead = $Length - $ReturnLength;
                        $Holder .= fread($this->Socket, $ToRead);
                        $ReturnLength = strlen($Holder);
                    }

                    $Result[] = $Holder;
                    $this->Debug('<< Read: [' . $ReturnLength . '/' . $Length . '] Bytes');
                }

                if ($Holder == "!done")
                    $IsDone = true;

                $Status = socket_get_status($this->Socket);

                if ($Length > 0)
                    $this->Debug('<< Read: [' . $Length . ', ' . $Status['unread_bytes'] . ']' . $Holder);

                if ((!$this->IsConnected && !$Status['unread_bytes']) || ($this->IsConnected && !$Status['unread_bytes'] && $IsDone))
                    break;
            }

            if ($Parse)
                $Result = $this->ParseResult($Result);

            return $Result;
        }

        public function ParseResult($Result)
        {
            if (is_array($Result))
            {
                $Current = null;
                $Parsed = array();
                $SingleValue = null;

                foreach ($Result as $X)
                {
                    if (in_array($X, array('!fatal','!re','!trap')))
                    {
                        if ($X == '!re')
                            $Current =& $Parsed[];
                        else
                            $Current =& $Parsed[$X][];
                    }
                    else if ($X != '!done')
                    {
                        $Found = array();
                        
                        if (preg_match_all('/[^=]+/i', $X, $Found))
                        {
                            if ($Found[0][0] == 'ret')
                                $SingleValue = $Found[0][1];

                            $Current[$Found[0][0]] = (isset($Found[0][1]) ? $Found[0][1] : '');
                        }
                    }
                }

                if (empty($Parsed) && !is_null($SingleValue))
                    $Parsed = $SingleValue;

                return $Parsed;
            }

            return array();
        }

        public function __destruct()
        {
            $this->Disconnect();
        }
    }
?>

<?php

    namespace App\Console\Commands;

    use App\Http\Controllers\Customer\DLRController;
    use App\Models\SendingServer;
    use Exception;
    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\Log;
    use SmppClient;
    use SocketTransport;

    class SMPPDLRReports extends Command
    {
        /**
         * The name and signature of the console command.
         *
         * @var string
         */
        protected $signature = 'smpp:dlr';

        /**
         * The console command description.
         *
         * @var string
         */
        protected $description = 'Fetch SMPP DLR statuses and inbound messages.';

        /**
         * Execute the console command.
         */
        public function handle(): void
        {
            $servers = SendingServer::query()
                ->select('id', 'name', 'api_link', 'port', 'username', 'password')
                ->where('status', true)
                ->where('type', 'smpp')
                ->get();

            if ($servers->isEmpty()) {
                $this->warn('No active SMPP servers found.');

                return;
            }

            // Preload error and status maps
            $statusMap = $this->getStatusMap();
            $errorMap  = $this->getErrorMap();

            foreach ($servers as $server) {
                $this->info("Processing SMPP DLR for Server ID: {$server->id} with name: {$server->name}");

                try {
                    $output = $this->fetchSmppResponse($server);

                    logger($output);

                    if (empty($output)) {
                        Log::warning("No SMPP response received for server ID: {$server->id} with name: {$server->name}");
                        continue;
                    }

                    if (empty($output) || ! is_array($output)) {
                        Log::warning("No SMPP response received for server ID: {$server->id} with name: {$server->name}");
                        continue;
                    }

                    foreach ($output as $line) {

                        $status = $line['status'];
                        $err    = $line['err_code'];

                        $status = $statusMap[$status] ?? $status;

                        if (in_array($status, ['Delivered', 'Rejected', 'Failed', 'Expired'])) {
                            $status = $errorMap[$err] ?? $err;
                        }

                        DLRController::updateDLR($line['id'], $status);
                        $this->info("DLR Updated → ID: {$line['id']}, Status: {$line['status']}");
                    }

                } catch (Exception $e) {
                    Log::error("SMPP DLR error for Server ID: {$server->id} with name: {$server->name}: " . $e->getMessage());
                    continue;
                }
            }
        }

        /**
         * Fetch SMPP response from server.
         * @throws Exception
         */
        private function fetchSmppResponse($server)
        {

            $transport        = new SocketTransport($server->api_link, (int) $server->port, 10, true);
            $transport->debug = false;

            // Set receive timeout to 5 seconds (5,000,000 microseconds)
            $transport->setRecvTimeout(5000000);

            // Create SMPP client
            $smpp               = new SmppClient($transport);
            $smpp->debug        = false;
            $smpp::$system_type = $server->c2;

            // Open connection and bind as receiver
            $transport->open();

            try {
                $smpp->bindReceiver($server->username, $server->password);
                $startTime = time();
                $maxWait   = 10; // max 10 seconds wait for incoming SMS

                $sms = [];

                while ((time() - $startTime) < $maxWait) {
                    try {
                        $message = $smpp->readSMS();
                        if (is_array($message) && isset($message[0])) {
                            $receipt = $message[0];
                            $id      = $receipt->id ?? null;
                            $stat    = $receipt->stat ?? null;
                            $err     = $receipt->err ?? null;

                            $sms[] = [
                                'id'       => $id,
                                'status'   => $stat,
                                'err_code' => $err,
                            ];

                        }
                    } catch (Exception $e) {

                        $this->info("Exception occurred: " . $e->getMessage());
                        // Resource temporarily unavailable, just wait and retry
                        usleep(50000); // wait 50ms before retry
                        continue;
                    }
                }

                logger($sms);

                return $sms;
            } catch (Exception $exception) {
                $this->info("Exception occurred: " . $exception->getMessage());
            } finally {
                $smpp->close();
            }

            return null;
        }

        /**
         * Map of DLR status translations.
         */
        private function getStatusMap(): array
        {
            return [
                'DELIVRD' => __('locale.labels.delivered'),
                'REJECTD' => __('locale.labels.rejected'),
                'FAILED'  => __('locale.labels.failed'),
                'EXPIRED' => __('locale.labels.expired'),
            ];
        }

        /**
         * Map of error codes.
         */
        private function getErrorMap(): array
        {
            return [
                '000'     => __('locale.labels.delivered'),
                '023'     => __('locale.bsnlerr.err023'),
                '025'     => __('locale.bsnlerr.err025'),
                '027'     => __('locale.bsnlerr.err027'),
                '070'     => __('locale.bsnlerr.err070'),
                '103'     => __('locale.bsnlerr.err103'),
                '107'     => __('locale.bsnlerr.err107'),
                '109'     => __('locale.bsnlerr.err109'),
                '252'     => __('locale.bsnlerr.err252'),
                '253'     => __('locale.bsnlerr.err253'),
                '600'     => __('locale.bsnlerr.err600'),
                '601'     => __('locale.bsnlerr.err601'),
                '602'     => __('locale.bsnlerr.err602'),
                '603'     => __('locale.bsnlerr.err603'),
                '604'     => __('locale.bsnlerr.err604'),
                '605'     => __('locale.bsnlerr.err605'),
                '606'     => __('locale.bsnlerr.err606'),
                '609'     => __('locale.bsnlerr.err609'),
                '610'     => __('locale.bsnlerr.err610'),
                '611'     => __('locale.bsnlerr.err611'),
                '612'     => __('locale.bsnlerr.err612'),
                '613'     => __('locale.bsnlerr.err613'),
                '619'     => __('locale.bsnlerr.err619'),
                '620'     => __('locale.bsnlerr.err620'),
                '621'     => __('locale.bsnlerr.err621'),
                '622'     => __('locale.bsnlerr.err622'),
                '623'     => __('locale.bsnlerr.err623'),
                '624'     => __('locale.bsnlerr.err624'),
                '629'     => __('locale.bsnlerr.err629'),
                '630'     => __('locale.bsnlerr.err630'),
                '631'     => __('locale.bsnlerr.err631'),
                '632'     => __('locale.bsnlerr.err632'),
                '633'     => __('locale.bsnlerr.err633'),
                '634'     => __('locale.bsnlerr.err634'),
                '635'     => __('locale.bsnlerr.err635'),
                '637'     => __('locale.bsnlerr.err637'),
                '638'     => __('locale.bsnlerr.err638'),
                '649'     => __('locale.bsnlerr.err649'),
                '650'     => __('locale.bsnlerr.err650'),
                '651'     => __('locale.bsnlerr.err651'),
                '652'     => __('locale.bsnlerr.err652'),
                '653'     => __('locale.bsnlerr.err653'),
                '659'     => __('locale.bsnlerr.err659'),
                '660'     => __('locale.bsnlerr.err660'),
                '661'     => __('locale.bsnlerr.err661'),
                '669'     => __('locale.bsnlerr.err669'),
                '670'     => __('locale.bsnlerr.err670'),
                '671'     => __('locale.bsnlerr.err671'),
                '699'     => __('locale.bsnlerr.err699'),

                // General SMPP & system errors
                '0'       => 'Delivered',
                '1'       => 'Message Length is invalid',
                '2'       => 'Command Length is invalid',
                '3'       => 'Invalid Command ID',
                '4'       => 'Incorrect BIND Status for given command',
                '5'       => 'ESME Already in Bound State',
                '6'       => 'Invalid Priority Flag',
                '7'       => 'Invalid Registered Delivery Flag',
                '8'       => 'System Error',
                '10'      => 'Invalid Source Address',
                '11'      => 'Invalid Destination Address',
                '12'      => 'Message ID is invalid',
                '13'      => 'Bind Failed',
                '14'      => 'Invalid Password',
                '15'      => 'Invalid System ID',
                '0x11'    => 'Cancel SM Failed',
                '0x13'    => 'Replace SM Failed',
                '0x15'    => 'Invalid Service Type',
                '0x33'    => 'Invalid number of destinations',
                '0x34'    => 'Invalid Distribution List name',
                '0x40'    => 'Destination flag is invalid (submit_multi)',
                '0x42'    => 'Submit w/replace unsupported or inappropriate',
                '0x43'    => 'Invalid esm_class field data',
                '0x44'    => 'Cannot Submit to Distribution List',
                '0x45'    => 'submit_sm/data_sm/submit_multi failed',
                '0x48'    => 'Invalid Source address TON',
                '0x49'    => 'Invalid Source address NPI',
                '0x51'    => 'Invalid Destination address NPI',
                '0x53'    => 'Invalid system_type field',
                '0x54'    => 'Invalid replace_if_present flag',
                '0x55'    => 'Invalid number of messages',
                '0x58'    => 'Throttling error (ESME exceeded limits)',
                '0x61'    => 'Invalid Scheduled Delivery Time',
                '0x62'    => 'Invalid message validity period',
                '0x63'    => 'Predefined Message ID is Invalid',
                '0x65'    => 'ESME Receiver Permanent App Error',
                '0x66'    => 'ESME Receiver Reject Message Error',
                '0x67'    => 'Query_Sm request failed',
                '0xc0'    => 'Error in optional part of the PDU Body',
                '0xc1'    => 'TLV not allowed',
                '0xc2'    => 'Invalid Parameter Length',
                '0xc3'    => 'Expected TLV missing',
                '0xc4'    => 'Invalid TLV Value',
                '0xfe'    => 'Transaction Delivery Failure',
                '0xff'    => 'Unknown Error',
                '0x100'   => 'ESME Not authorized to use specified service type',
                '0x101'   => 'ESME Prohibited from using operation (Blacklisted)',
                '0x102'   => 'Specified service type is unavailable',
                '0x103'   => 'Specified service type is denied',
                '0x105'   => 'Source Address Sub unit is Invalid',
                '0x106'   => 'Destination Address Sub unit is Invalid',
                '0x107'   => 'Broadcast Frequency Interval is invalid',
                '0x108'   => 'Broadcast Alias Name is invalid',
                '0x109'   => 'Broadcast Area Format is invalid',
                '0x10a'   => 'Number of Broadcast Areas is invalid',
                '0x10b'   => 'Broadcast Content Type is invalid',
                '0x10c'   => 'Broadcast Message Class is invalid',
                '0x10d'   => 'Broadcast_sm operation failed',
                '0x10f'   => 'Cancel_broadcast_sm operation failed',
                '0x110'   => 'Number of Repeated Broadcasts is invalid',
                '0x111'   => 'Broadcast Service Group is invalid',
                '0x112'   => 'Broadcast Channel Indicator is invalid',
                '0x15f95' => 'Local Exception. Check LastException property',

                // Network-related / routing errors
                '439'     => 'Country Undef',
                '43a'     => 'Invalid Network',
                '43b'     => 'Price Undefined',
                '43c'     => 'Expired (Loss Protection)',
                '43d'     => 'Route undef',
                '43e'     => 'Failover Route Undef',
                '43f'     => 'Failover expired',
                '440'     => 'Failover Price Undef',
                '441'     => 'Failover Failed',
                '442'     => 'Account Validity Expired',
                '443'     => 'Encoding error',
                '444'     => 'OA/DA Error',
                '445'     => 'Queue Message Expired',
                '446'     => 'Invalid HTTP gateway config',
                '417'     => 'User account inactive',

                // Custom / vendor-specific errors
                '0x400'   => 'User Account Locked',
                '0x401'   => 'Insufficient balance',
                '0x402'   => 'Template Mismatch',
                '0x405'   => 'Invalid sender id',
                '0x40b'   => 'No active connection',
                '0x40c'   => 'Queue server error',
                '0x40d'   => 'Cache server error',
                '0x40e'   => 'Invalid connection state',
                '0x40f'   => 'Smpp timeout',
                '0x410'   => 'Unknown SMPP error',
                '0x411'   => 'Final disconnection',
                '0x412'   => 'Final timeout',
                '0x413'   => 'No gateway found',
                '0x404'   => 'Invalid IP',
                '0x414'   => 'Spam detected',

                // HTTP / API specific
                '110'     => 'Error while getting text response',
                '111'     => 'Error while getting JSON/XML response',
                '112'     => 'Error while reading response',
                '113'     => 'Error reading response from result',
                '114'     => 'Error requesting rest/json api',
                '115'     => 'Error requesting simple http api',
                '116'     => 'Error requesting SOAP api',
                '1095'    => 'Combined SOAP/Simple/RestJson error',
            ];
        }

    }

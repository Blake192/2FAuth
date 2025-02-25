<?php

namespace App\Services\Migrators;

use App\Services\Migrators\Migrator;
use Illuminate\Support\Collection;
use App\Models\TwoFAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use App\Exceptions\InvalidMigrationDataException;

class TwoFASMigrator extends Migrator
{
    // {
    //     "groups":
    //     [
    //         {
    //             "id": "C2F69014-C4C7-4EEC-9225-D24E750F77FD",
    //             "name": "Test",
    //             "isExpanded": true
    //         }
    //     ],
    //     "schemaVersion": 2,
    //     "appOrigin": "ios",
    //     "appVersionCode": 32001,
    //     "services":
    //     [
    //         {
    //             "secret": "NRTWO2DLNJUGO23KM5UA",
    //             "badge":
    //             {
    //                 "color": "Default"
    //             },
    //             "order":
    //             {
    //                 "position": 0
    //             },
    //             "otp":
    //             {
    //                 "account": "My account",
    //                 "digits": 6,
    //                 "counter": 0,
    //                 "period": 30,
    //                 "algorithm": "SHA1",
    //                 "tokenType": "TOTP"
    //             },
    //             "updatedAt": 1657529936000,
    //             "type": "ManuallyAdded",
    //             "name": "My Service",
    //             "icon":
    //             {
    //                 "selected": "Brand",
    //                 "brand":
    //                 {
    //                     "id": "ManuallyAdded"
    //                 },
    //                 "label":
    //                 {
    //                     "text": "OW",
    //                     "backgroundColor": "LightBlue"
    //                 }
    //             }
    //         }
    //     ],
    //     "appVersionName": "3.20.1"
    // }


    /**
     * Convert migration data to a TwoFAccounts collection.
     *
     * @param  mixed  $migrationPayload
     * @return \Illuminate\Support\Collection The converted accounts
     */
    public function migrate(mixed $migrationPayload) : Collection
    {
        $json = json_decode(htmlspecialchars_decode($migrationPayload), true);

        if (is_null($json) || Arr::has($json, 'services') == false) {
            Log::error('Aegis JSON migration data cannot be read');
            throw new InvalidMigrationDataException('2FAS Auth');
        }
        
        $twofaccounts = array();

        foreach ($json['services'] as $key => $otp_parameters) {

            $parameters = array();
            $parameters['otp_type']     = $otp_parameters['otp']['tokenType'];
            $parameters['service']      = $otp_parameters['name'];
            $parameters['account']      = $otp_parameters['otp']['account'] ?? $parameters['service'];
            $parameters['secret']       = $this->padToValidBase32Secret($otp_parameters['secret']);
            $parameters['algorithm']    = $otp_parameters['otp']['algorithm'];
            $parameters['digits']       = $otp_parameters['otp']['digits'];
            $parameters['counter']      = $otp_parameters['otp']['counter'] ?? null;
            $parameters['period']       = $otp_parameters['otp']['period'] ?? null;

            try {
               $twofaccounts[$key] = new TwoFAccount;
               $twofaccounts[$key]->fillWithOtpParameters($parameters);
            }
            catch (\Exception $exception) {

                Log::error(sprintf('Cannot instanciate a TwoFAccount object with 2FAS imported item #%s', $key));
                Log::error($exception->getMessage());

                // The token failed to generate a valid account so we create a fake account to be returned.
                $fakeAccount = new TwoFAccount();
                $fakeAccount->id = TwoFAccount::FAKE_ID;
                $fakeAccount->otp_type  = $otp_parameters['otp']['tokenType'] ?? TwoFAccount::TOTP;
                // Only basic fields are filled to limit the risk of another exception.
                $fakeAccount->account   = $otp_parameters['otp']['account'] ?? __('twofaccounts.import.invalid_account');
                $fakeAccount->service   = $otp_parameters['name'] ?? __('twofaccounts.import.invalid_service');
                // The secret field is used to pass the error, not very clean but will do the job for now.
                $fakeAccount->secret    = $exception->getMessage();

                $twofaccounts[$key] = $fakeAccount;
            }
        }

        return collect($twofaccounts);
    }
}

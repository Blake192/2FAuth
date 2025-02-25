<?php

namespace App\Services\Migrators;

use Exception;
use App\Models\TwoFAccount;
use App\Services\Migrators\Migrator;
use Illuminate\Support\Collection;
use ParagonIE\ConstantTime\Base32;
use App\Protobuf\GAuthValueMapping;
use App\Protobuf\GoogleAuth\Payload;
use App\Protobuf\GoogleAuth\Payload\OtpType;
use App\Protobuf\GoogleAuth\Payload\Algorithm;
use App\Protobuf\GoogleAuth\Payload\DigitCount;
use App\Exceptions\InvalidMigrationDataException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleAuthMigrator extends Migrator
{

    /**
     * Convert Google Authenticator migration URI to a set of TwoFAccount objects.
     *
     * @param  mixed  $migrationPayload migration uri provided by Google Authenticator export feature
     * 
     * @return \Illuminate\Support\Collection The converted accounts
     */
    public function migrate(mixed $migrationPayload) : Collection
    {
        try {
            $migrationData = base64_decode(urldecode(Str::replace('otpauth-migration://offline?data=', '', $migrationPayload)));
            $protobuf = new Payload();
            $protobuf->mergeFromString($migrationData);
            $otpParameters = $protobuf->getOtpParameters();
        }
        catch (Exception $ex) {
            Log::error("Protobuf failed to get OTP parameters from provided migration URI");
            Log::error($ex->getMessage());

            throw new InvalidMigrationDataException('Google Authenticator');
        }

        $twofaccounts = array();
        
        foreach ($otpParameters->getIterator() as $key => $otp_parameters) {

             try {
                $parameters = array();
                $parameters['otp_type']     = GAuthValueMapping::OTP_TYPE[OtpType::name($otp_parameters->getType())];
                $parameters['service']      = $otp_parameters->getIssuer();
                $parameters['account']      = str_replace($parameters['service'].':', '', $otp_parameters->getName());
                $parameters['secret']       = Base32::encodeUpper($otp_parameters->getSecret());
                $parameters['algorithm']    = GAuthValueMapping::ALGORITHM[Algorithm::name($otp_parameters->getAlgorithm())];
                $parameters['digits']       = GAuthValueMapping::DIGIT_COUNT[DigitCount::name($otp_parameters->getDigits())];
                $parameters['counter']      = $parameters['otp_type'] === TwoFAccount::HOTP ? $otp_parameters->getCounter() : null;
                $parameters['period']       = $parameters['otp_type'] === TwoFAccount::TOTP ? $otp_parameters->getPeriod() : null;

                $twofaccounts[$key] = new TwoFAccount;
                $twofaccounts[$key]->fillWithOtpParameters($parameters);
            }
            catch (Exception $exception) {

                Log::error(sprintf('Cannot instanciate a TwoFAccount object with OTP parameters from imported item #%s', $key));
                Log::error($exception->getMessage());

                // The token failed to generate a valid account so we create a fake account to be returned.
                $fakeAccount = new TwoFAccount();
                $fakeAccount->id = -2;
                $fakeAccount->otp_type  = $fakeAccount::TOTP;
                // Only basic fields are filled to limit the risk of another exception.
                $fakeAccount->account   = $otp_parameters->getName() ?? __('twofaccounts.import.invalid_account');
                $fakeAccount->service   = $otp_parameters->getIssuer() ?? __('twofaccounts.import.invalid_service');
                // The secret field is used to pass the error, not very clean but will do the job for now.
                $fakeAccount->secret    = $exception->getMessage();

                $twofaccounts[$key] = $fakeAccount;
            }
        }

        return collect($twofaccounts);
    }
}

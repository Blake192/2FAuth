<template>
    <div>
        <figure class="image is-64x64" :class="{ 'no-icon': !internal_icon }" style="display: inline-block">
            <img :src="'/storage/icons/' + internal_icon" v-if="internal_icon" :alt="$t('twofaccounts.icon_to_illustrate_the_account')">
        </figure>
        <p class="is-size-4 has-text-grey-light has-ellipsis">{{ internal_service }}</p>
        <p class="is-size-6 has-text-grey has-ellipsis">{{ internal_account }}</p>
        <p>
            <span role="log" ref="otp" tabindex="0" class="otp is-size-1 has-text-white is-clickable px-3" @click="copyOTP(internal_password)" @keyup.enter="copyOTP(internal_password)" :title="$t('commons.copy_to_clipboard')">
                {{ displayedOtp }}
            </span>
        </p>
        <ul class="dots" v-show="isTimeBased(internal_otp_type)">
            <li v-for="n in 10" :key="n"></li>
        </ul>
        <ul v-show="isHMacBased(internal_otp_type)">
            <li>counter: {{ internal_counter }}</li>
        </ul>
    </div>
</template>

<script>
    export default {
        name: 'OtpDisplayer',

        data() {
            return {
                internal_id: null,
                internal_otp_type: '',
                internal_account: '',
                internal_service: '',
                internal_icon: '',
                internal_secret: null,
                internal_digits: null,
                internal_algorithm: null,
                internal_period: null,
                internal_counter: null,
                internal_password : '',
                internal_uri : '',
                lastActiveDot: null,
                remainingTimeout: null,
                firstDotToNextOneTimeout: null,
                dotToDotInterval: null
            }
        },

        props: {
            otp_type : String,
            account : String,
            service : String,
            icon : String,
            secret : String,
            digits : Number,
            algorithm : String,
            period : null,
            counter : null,
            image : String,
            qrcode : null,
            uri : String
        },

        computed: {
            displayedOtp() {
                let pwd = this.internal_password
                if (this.internal_otp_type !== 'steamtotp') {
                    const spacePosition = Math.ceil(this.internal_password.length / 2)
                    pwd = this.internal_password.substr(0, spacePosition) + " " + this.internal_password.substr(spacePosition)
                }
                return this.$root.appSettings.showOtpAsDot ? pwd.replace(/[0-9]/g, '●') : pwd
            },
        },

        mounted: function() {
            this.show()
        },

        // created() {
        // },

        methods: {

            copyOTP (otp) {
                // see https://web.dev/async-clipboard/ for futur Clipboard API usage.
                // The API should allow to copy the password on each trip without user interaction.

                // For now too many browsers don't support the clipboard-write permission
                // (see https://developer.mozilla.org/en-US/docs/Web/API/Permissions#browser_support)

                const rawOTP = otp.replace(/ /g, '')
                const success = this.$clipboard(rawOTP)

                if (success == true) {
                    if(this.$root.appSettings.kickUserAfter == -1) {
                        this.appLogout()
                    }
                    else if(this.$root.appSettings.closeOtpOnCopy) {
                        this.$parent.isActive = false
                        this.clearOTP()
                    }

                    this.$notify({ type: 'is-success', text: this.$t('commons.copied_to_clipboard') })
                }
            },

            isTimeBased: function(otp_type) {
                return (otp_type === 'totp' || otp_type === 'steamtotp')
            },

            isHMacBased: function(otp_type) {
                return otp_type === 'hotp'
            },

            async show(id) {

                // 3 possible cases :
                //   - Trigger when user ask for an otp of an existing account: the ID is provided so we fetch the account data
                //     from db but without the uri.
                //     This prevent the uri (a sensitive data) to transit via http request unnecessarily. In this
                //     case this.otp_type is sent by the backend.
                //   - Trigger when user use the Quick Uploader and preview the account: No ID but we have an URI.
                //   - Trigger when user use the Advanced form and preview the account: We should have all OTP parameter
                //     to obtain an otp, including Secret and otp_type which are required

                this.internal_otp_type = this.otp_type
                this.internal_account = this.account
                this.internal_service = this.service
                this.internal_icon = this.icon
                this.internal_secret = this.secret
                this.internal_digits = this.digits
                this.internal_algorithm = this.algorithm
                this.internal_period = this.period
                this.internal_counter = this.counter

                if( id ) {

                    this.internal_id = id
                    const { data } = await this.axios.get('api/v1/twofaccounts/' + this.internal_id)

                    this.internal_service = data.service
                    this.internal_account = data.account
                    this.internal_icon = data.icon
                    this.internal_otp_type = data.otp_type
                    
                    if( this.isHMacBased(data.otp_type) && data.counter ) {
                        this.internal_counter = data.counter
                    }
                }

                // We force the otp_type to be based on the uri
                if( this.uri ) {
                    this.internal_uri = this.uri
                    this.internal_otp_type = this.uri.slice(0, 15 ).toLowerCase() === "otpauth://totp/" ? 'totp' : 'hotp';
                }

                if( this.internal_id || this.uri || this.secret ) { // minimun required vars to get an otp from the backend
                    try {
                        if(this.isTimeBased(this.internal_otp_type)) {
                            await this.startTotpLoop()
                        }
                        else if(this.isHMacBased(this.internal_otp_type)) {
                            await this.getHOTP()
                        }
                        else this.$router.push({ name: 'genericError', params: { err: this.$t('errors.not_a_supported_otp_type') } });
    
                        this.$parent.isActive = true
                        this.focusOnOTP()
                    }
                    catch(error) {
                        this.clearOTP()
                    }
                }
            },

            getOtp: async function() {

                try {
                    let request, password

                    if(this.internal_id) {
                        request = {
                            method: 'get',
                            url: '/api/v1/twofaccounts/' + this.internal_id + '/otp'
                        }
                    }
                    else if(this.internal_uri) {
                        request = {
                            method: 'post',
                            url: '/api/v1/twofaccounts/otp',
                            data: {
                                uri: this.internal_uri
                            }
                        }
                    }
                    else {
                        request = {
                            method: 'post',
                            url: '/api/v1/twofaccounts/otp',
                            data: {
                                service     : this.internal_service,
                                account     : this.internal_account,
                                icon        : this.internal_icon,
                                otp_type    : this.internal_otp_type,
                                secret      : this.internal_secret,
                                digits      : this.internal_digits,
                                algorithm   : this.internal_algorithm,
                                period      : this.internal_period,
                                counter     : this.internal_counter,
                            }
                        }
                    }

                    await this.axios(request).then(response => {
                        if(this.$root.appSettings.copyOtpOnDisplay) {
                            this.copyOTP(response.data.password)
                        }
                        password = response.data
                    })

                    return password

                }
                catch(error) {
                    if (error.response.status === 422) {
                        this.$emit('validation-error', error.response)
                    }
                    throw error
                }
            },

            startTotpLoop: async function() {
                
                let otp = await this.getOtp()

                this.internal_password = otp.password
                this.internal_otp_type = otp.otp_type

                let generated_at = otp.generated_at
                let period = otp.period

                let elapsedTimeInCurrentPeriod,
                    remainingTimeBeforeEndOfPeriod,
                    durationBetweenTwoDots,
                    durationFromFirstToNextDot,
                    dots

                //                              |<----period p----->|
                //     |                        |                   |
                //     |------- ··· ------------|--------|----------|---------->
                //     |                        |        |          |
                //  unix T0                 Tp.start   Tgen_at    Tp.end
                //                              |        |          |
                //  elapsedTimeInCurrentPeriod--|<------>|          |
                //  (in ms)                     |        |          |
                //                              ● ● ● ● ●|● ◌ ◌ ◌ ◌ |
                //                              | |      ||         |
                //                              | |      |<-------->|--remainingTimeBeforeEndOfPeriod (for remainingTimeout)
                //     durationBetweenTwoDots-->|-|<     ||         
                //     (for dotToDotInterval)   | |     >||<---durationFromFirstToNextDot (for firstDotToNextOneTimeout)
                //                                        |
                //                                        |
                //                                     dotIndex

                // The elapsed time from the start of the period that contains the OTP generated_at timestamp and the OTP generated_at timestamp itself
                elapsedTimeInCurrentPeriod = generated_at % period

                // Switch off all dots
                dots = this.$el.querySelector('.dots')
                while (dots.querySelector('[data-is-active]')) {
                    dots.querySelector('[data-is-active]').removeAttribute('data-is-active');
                }

                // We determine the position of the closest dot next to the generated_at timestamp
                let relativePosition = (elapsedTimeInCurrentPeriod * 10) / period
                let dotIndex = (Math.floor(relativePosition) +1)
                
                // We switch the dot on
                this.lastActiveDot = dots.querySelector('li:nth-child(' + dotIndex + ')');
                this.lastActiveDot.setAttribute('data-is-active', true);

                // Main timeout that run until the end of the period
                remainingTimeBeforeEndOfPeriod = period - elapsedTimeInCurrentPeriod
                let self = this; // because of the setInterval/setTimeout closures

                this.remainingTimeout = setTimeout(function() {
                    self.stopLoop()
                    self.startTotpLoop();
                }, remainingTimeBeforeEndOfPeriod*1000);

                // During the remainingTimeout countdown we have to show a next dot every durationBetweenTwoDots seconds
                // except for the first next dot
                durationBetweenTwoDots = period / 10 // we have 10 dots
                durationFromFirstToNextDot = (Math.ceil(elapsedTimeInCurrentPeriod / durationBetweenTwoDots) * durationBetweenTwoDots) - elapsedTimeInCurrentPeriod

                this.firstDotToNextOneTimeout = setTimeout(function() {
                    if( durationFromFirstToNextDot > 0 ) {
                        self.activateNextDot()
                        dotIndex += 1
                    }
                    self.dotToDotInterval = setInterval(function() {
                        self.activateNextDot()
                        dotIndex += 1
                    }, durationBetweenTwoDots*1000)
                }, durationFromFirstToNextDot*1000)
            },


            getHOTP: async function() {

                let otp = await this.getOtp()
                this.internal_password = otp.password
                this.internal_counter = otp.counter

                // returned counter & uri are incremented
                this.$emit('increment-hotp', { nextHotpCounter: otp.counter, nextUri: otp.uri })
            },


            clearOTP: function() {

                this.stopLoop()
                this.internal_id = this.remainingTimeout = this.dotToDotInterval = this.firstDotToNextOneTimeout = this.elapsedTimeInCurrentPeriod = this.internal_counter = null
                this.internal_service = this.internal_account = this.internal_icon = this.internal_otp_type = this.internal_secret = ''
                this.internal_password = '... ...'

                try {
                    this.$el.querySelector('[data-is-active]').removeAttribute('data-is-active');
                    this.$el.querySelector('.dots li:first-child').setAttribute('data-is-active', true);
                }
                catch(e) {
                    // we do not throw anything
                }
            },


            stopLoop: function() {
                if( this.isTimeBased(this.internal_otp_type) ) {
                    clearTimeout(this.remainingTimeout)
                    clearTimeout(this.firstDotToNextOneTimeout)
                    clearInterval(this.dotToDotInterval)
                }
            },


            activateNextDot: function() {
                if(this.lastActiveDot.nextSibling !== null) {
                    this.lastActiveDot.removeAttribute('data-is-active')
                    this.lastActiveDot.nextSibling.setAttribute('data-is-active', true)
                    this.lastActiveDot = this.lastActiveDot.nextSibling
                }
            },

            focusOnOTP() {
                this.$nextTick(() => {
                    this.$refs.otp.focus()
                })
            }

        },

        beforeDestroy () {
            this.stopLoop()
        }
    }
</script>
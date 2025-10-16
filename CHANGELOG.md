<!--- BEGIN HEADER -->
# Changelog

All notable changes to this project will be documented in this file.
<!--- END HEADER -->

## [1.0.56](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.55...v1.0.56) (2025-10-16)

### Features

* Improve shutdown function error message for clearer debugging ([761df8](https://github.com/liquiddesign/liquid-monitor-connector/commit/761df8d81d8e959b0f39e9851e1cae9f12ee0763))


---

## [1.0.55](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.54...v1.0.55) (2025-07-06)

### Features

* Improve shutdown function error message for clearer debugging ([bd983b](https://github.com/liquiddesign/liquid-monitor-connector/commit/bd983bbab6b31cbdcc62cb680d7f4652fe06df05))


---

## [1.0.54](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.53...v1.0.54) (2025-07-02)

### Features

* Add exception messages to ExceptionToJsonArray output ([4cc408](https://github.com/liquiddesign/liquid-monitor-connector/commit/4cc408174623f2d257cd2685f5bcf35e2b448f1b))


---

## [1.0.53](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.52...v1.0.53) (2025-07-02)


---

## [1.0.52](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.51...v1.0.52) (2025-07-02)


---

## [1.0.51](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.50...v1.0.51) (2025-07-02)

### Features

* Include support for capturing previous exceptions in ExceptionToJsonArray ([f5d03a](https://github.com/liquiddesign/liquid-monitor-connector/commit/f5d03a067fdc04c158238cb598b79d845bb6828d))


---

## [1.0.50](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.49...v1.0.50) (2025-05-26)

### Features

* Update arguments serialization in Cron.php to handle null values ([d07b49](https://github.com/liquiddesign/liquid-monitor-connector/commit/d07b493d974f92462aa0d15395a643473e12eb78))


---

## [1.0.49](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.48...v1.0.49) (2025-05-08)

### Features

* Add getArguments method and support for serialized arguments in Cron.php ([f03165](https://github.com/liquiddesign/liquid-monitor-connector/commit/f03165e732d676153c87c761c8139dbfce25f915))
* Add getArguments method to Cron.php and update dependencies in LiquidMonitorConnectorDI ([de6bde](https://github.com/liquiddesign/liquid-monitor-connector/commit/de6bde55a77f0151b6a899c6ccc5c869db7ce393))
* Add GetCronService class and register it in LiquidMonitorConnectorDI ([618c5e](https://github.com/liquiddesign/liquid-monitor-connector/commit/618c5e6c206a9effe74164103e690648469795db))
* Introduce LiquidMonitorDisabledException and handle it in Cron.php ([6f40f2](https://github.com/liquiddesign/liquid-monitor-connector/commit/6f40f2334d0676d63d5cad132e02f1e3c90f83b9))


---

## [1.0.48](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.47...v1.0.48) (2025-03-22)

### Features

* Add Nette\Security dependency and user identity logging to LiquidMonitorLogger ([109c99](https://github.com/liquiddesign/liquid-monitor-connector/commit/109c99babe49dff3598957525f60ebf929bdd376))


---

## [1.0.47](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.46...v1.0.47) (2025-03-16)

### Features

* Add cron job log ID to logging data in LiquidMonitorLogger ([d483dd](https://github.com/liquiddesign/liquid-monitor-connector/commit/d483dda7da7cbe9f38ec97207eaba42795023797))

### Bug Fixes

* Rename 'cron_job_log_id' to 'job_id' in LiquidMonitorLogger ([cc251e](https://github.com/liquiddesign/liquid-monitor-connector/commit/cc251e42ab853087c250d9952d3ec8d8540a7452))


---

## [1.0.46](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.45...v1.0.46) (2025-03-10)

### Bug Fixes

* Set weak flag to true for WeakException handling in LiquidMonitorLogger.php ([a54e9a](https://github.com/liquiddesign/liquid-monitor-connector/commit/a54e9a4355fe41ac360b56c1c05a2142f035507c))


---

## [1.0.45](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.44...v1.0.45) (2025-03-05)


---

## [1.0.44](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.43...v1.0.44) (2025-03-05)

### ⚠ BREAKING CHANGES

* Change default value of createIfNotExists to true in Cron.php ([ba6947](https://github.com/liquiddesign/liquid-monitor-connector/commit/ba69478355ceda270686b60331602bcd78bef635))

### Bug Fixes

* Cast memory usage to integer for accurate reporting in Cron.php ([fe120d](https://github.com/liquiddesign/liquid-monitor-connector/commit/fe120dec6cb4fc869df9fdaa57c8aa2c09f50ca9))


---

## [1.0.44](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.43...v1.0.44) (2025-02-28)

### Bug Fixes

* Cast memory usage to integer for accurate reporting in Cron.php ([fe120d](https://github.com/liquiddesign/liquid-monitor-connector/commit/fe120dec6cb4fc869df9fdaa57c8aa2c09f50ca9))


---

## [1.0.43](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.42...v1.0.43) (2025-02-14)

### Bug Fixes

* Cast memory usage to integer for job finish parameters ([9e5e3f](https://github.com/liquiddesign/liquid-monitor-connector/commit/9e5e3f161ad181fd304125f6ebd708398bd7d41f))


---

## [1.0.42](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.41...v1.0.42) (2025-02-14)

### Bug Fixes

* Update error logging in Cron.php to use a specific context ([f30129](https://github.com/liquiddesign/liquid-monitor-connector/commit/f3012905946f4351ca60963e33c01a39e1029afe))


---

## [1.0.41](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.40...v1.0.41) (2025-02-14)

### Features

* Include memory usage in params for job finish and fail endpoints ([867587](https://github.com/liquiddesign/liquid-monitor-connector/commit/867587132e6f7080aa4e7acbc5433721c1ff5883))


---

## [1.0.40](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.39...v1.0.40) (2025-02-11)

### Features

* Remove trace array slicing in LiquidMonitorLogger ([3edc00](https://github.com/liquiddesign/liquid-monitor-connector/commit/3edc0007a08cd5dd203da294988c33c90d90cab3))


---

## [1.0.39](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.38...v1.0.39) (2024-11-29)

### Features

* Add INFO case to HealthCheckStatusEnum for additional status tracking ([4b0243](https://github.com/liquiddesign/liquid-monitor-connector/commit/4b0243abdf1fde94c69512d12ab92a086c79e79f))


---

## [1.0.38](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.37...v1.0.38) (2024-10-27)

### Features

* Add createIfNotExists parameter to Cron class methods ([31f1c2](https://github.com/liquiddesign/liquid-monitor-connector/commit/31f1c2d365e79a42933364b8afbc6f6eb7eccf5a))


---

## [1.0.37](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.36...v1.0.37) (2024-10-21)

### Features

* Add Carbon dependency and update cron API request ([5e2fc8](https://github.com/liquiddesign/liquid-monitor-connector/commit/5e2fc846fe71c7de2e0e9842df68705f5a2b3259))


---

## [1.0.36](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.35...v1.0.36) (2024-10-21)

### Features

* Add health check classes and update dependencies ([ab098d](https://github.com/liquiddesign/liquid-monitor-connector/commit/ab098d024c8cc3bb0f983fcbf76ed378770631d2))
* Add isCronRunning method to check cron job status ([675d4a](https://github.com/liquiddesign/liquid-monitor-connector/commit/675d4a682e74bf6285f02bf268326358d20f1c1b))


---

## [1.0.35](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.34...v1.0.35) (2024-09-04)

### Features

* Increase HTTP request timeout and catch all exceptions ([c73c3e](https://github.com/liquiddesign/liquid-monitor-connector/commit/c73c3eff4770d1957db7796122a871dd9052bea2))


---

## [1.0.34](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.33...v1.0.34) (2024-08-22)

### Bug Fixes

* Disable exception logging in Cron class ([03d050](https://github.com/liquiddesign/liquid-monitor-connector/commit/03d0506029c6c0763bddf6df10a637c0b489fb09))


---

## [1.0.33](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.32...v1.0.33) (2024-08-22)

### Bug Fixes

* Comment out cron job progress update to prevent infinite loop ([d5003f](https://github.com/liquiddesign/liquid-monitor-connector/commit/d5003f3c7d5ba0ec912e91f5c7c02738ab286b1e))


---

## [1.0.32](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.31...v1.0.32) (2024-08-04)

### Features

* Refactor WeakException and add weak flag to LiquidMonitorLogger ([d777c5](https://github.com/liquiddesign/liquid-monitor-connector/commit/d777c5140c9c6a63786542f69a4624c84e77b9f4))


---

## [1.0.31](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.30...v1.0.31) (2024-07-31)

### Features

* Allow string data in Cron job methods ([a2bb83](https://github.com/liquiddesign/liquid-monitor-connector/commit/a2bb83989651e9abaee2e8f05fcba5382dbe72cd))


---

## [1.0.30](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.29...v1.0.30) (2024-07-29)

### Bug Fixes

* Check if job ID exists before calling progressJob ([752b89](https://github.com/liquiddesign/liquid-monitor-connector/commit/752b8919d5bd82effcebc61c31845eb2ec3d8961))


---

## [1.0.29](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.28...v1.0.29) (2024-07-12)

### Features

* Make getJobId method public and add job progress logging ([670e6e](https://github.com/liquiddesign/liquid-monitor-connector/commit/670e6e90c90d279a4b3e6c7946d2256a8f9f1fd7))


---

## [1.0.28](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.27...v1.0.28) (2024-07-12)

### Features

* Add parameters for cron job scheduling and configuration ([4b189e](https://github.com/liquiddesign/liquid-monitor-connector/commit/4b189eb818c986b7b74e7f1d37bee3d92fe810fd))


---

## [1.0.27](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.26...v1.0.27) (2024-07-12)

### Features

* Add parameters for cron job scheduling and configuration ([daa5e6](https://github.com/liquiddesign/liquid-monitor-connector/commit/daa5e6495d20d6e558adb5e168ec460eebabac28))


---

## [1.0.26](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.25...v1.0.26) (2024-06-17)

### Bug Fixes


##### Cron

* Add exception handling ([14bb9a](https://github.com/liquiddesign/liquid-monitor-connector/commit/14bb9a97381aa99314cbaa327c3d4763cd03873a))


---

## [1.0.25](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.24...v1.0.25) (2024-06-17)

### Bug Fixes


##### Cron

* Add exception ([5e4717](https://github.com/liquiddesign/liquid-monitor-connector/commit/5e4717baa2ee6cbe09870f67c27a42403173a7fc))


---

## [1.0.24](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.23...v1.0.24) (2024-06-14)

### Features


##### DI

* Remove unused options ([d8a74b](https://github.com/liquiddesign/liquid-monitor-connector/commit/d8a74bb2468a3fed7f3793518c33feccf9893761))


---

## [1.0.23](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.22...v1.0.23) (2024-06-14)

### Features


##### DI

* New options, apiKey not required ([61a20a](https://github.com/liquiddesign/liquid-monitor-connector/commit/61a20ad315dd9d13ed33fd9a0617b82038b5ee0c))


---

## [1.0.22](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.21...v1.0.22) (2024-06-13)

### Features


##### Cron

* ScheduleOrStartJob can create Cron if not exists, throw exceptions ([edba52](https://github.com/liquiddesign/liquid-monitor-connector/commit/edba52deacd169f09aeb7e0cf032cee75d4ebeaf))


---

## [1.0.21](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.20...v1.0.21) (2024-06-12)

### Features


##### Liquid Monitor Logger

* Move code to new class, add formating to Cron ([1026e4](https://github.com/liquiddesign/liquid-monitor-connector/commit/1026e4cf1406ea1589f76927b510d1ea44ef4757))


---

## [1.0.20](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.19...v1.0.20) (2024-06-12)

### Features


##### Liquid Monitor Logger

* New trace formats ([ff03d2](https://github.com/liquiddesign/liquid-monitor-connector/commit/ff03d2a11d09a0d778e1f11d24a3f1f9d0768793))


---

## [1.0.19](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.18...v1.0.19) (2024-05-25)

### Features

* Allow to pass Exception to Cron logging methods ([7ae0fd](https://github.com/liquiddesign/liquid-monitor-connector/commit/7ae0fda6df8b1e5c36f300f0948e7acb38cd7d8c))

##### Liquid Monitor Logger DI

* New check if LiquidMonitorConnector is registered, title is optional ([de9acc](https://github.com/liquiddesign/liquid-monitor-connector/commit/de9accbd60e54b635545a4e9d548d9884fc82df5))
* Title is optional ([84ef16](https://github.com/liquiddesign/liquid-monitor-connector/commit/84ef16c500466a870e6401cc8e952b4982748b80))


---

## [1.0.18](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.17...v1.0.18) (2024-05-21)

### Features

* Add skipMonitor parameter ([c7b81d](https://github.com/liquiddesign/liquid-monitor-connector/commit/c7b81dc593bde8e28db5b84040228a6697e6f92d))


---

## [1.0.17](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.16...v1.0.17) (2024-05-17)

### Features

* Add log of exception ([025ff4](https://github.com/liquiddesign/liquid-monitor-connector/commit/025ff4d1ff8537979c9f1a5fdfbad4ed3e68b058))


---

## [1.0.16](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.15...v1.0.16) (2024-05-17)

### Features

* Remove log ([87a5fe](https://github.com/liquiddesign/liquid-monitor-connector/commit/87a5fed1ab85a5b180656a99a5c3e651701554eb))


---

## [1.0.15](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.14...v1.0.15) (2024-05-16)

### Features

* Re-enable debug_backtrace and simplify Exception trace ([7d4bd0](https://github.com/liquiddesign/liquid-monitor-connector/commit/7d4bd0fa649280a0c39272b7a99ad3473529d4d4))
* Re-enable debug_backtrace and simplify traces ([08d07c](https://github.com/liquiddesign/liquid-monitor-connector/commit/08d07c443366896ec1bfdae1b0c36765f43783ed))


---

## [1.0.14](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.13...v1.0.14) (2024-05-10)

### Features

* Disable debug_backtrace ([cce74f](https://github.com/liquiddesign/liquid-monitor-connector/commit/cce74f1689ceb8bf01f47fe9e62a3f8239b69d9a))


---

## [1.0.13](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.12...v1.0.13) (2024-05-02)

### Bug Fixes


##### Logger

* Change exception to critical ([dee956](https://github.com/liquiddesign/liquid-monitor-connector/commit/dee95680c74af06ac4f8161af52db08a0c1a953f))


---

## [1.0.12](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.11...v1.0.12) (2024-04-24)

### Features

* Simplify debug_backtrace ([6a6fc7](https://github.com/liquiddesign/liquid-monitor-connector/commit/6a6fc7e10fb52fe98ce725e5f0fb33d015e820ec))


---

## [1.0.11](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.10...v1.0.11) (2024-04-19)

### Features

* Change JobId to be string ([850a8b](https://github.com/liquiddesign/liquid-monitor-connector/commit/850a8b22d48eab9ee18237fb907a32c593828273))


---

## [1.0.10](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.9...v1.0.10) (2024-04-16)

### Bug Fixes


##### Logger

* Cast code to string, try json decode ([d49f45](https://github.com/liquiddesign/liquid-monitor-connector/commit/d49f45334ab33ab9873f5404587356d790986e82))


---

## [1.0.9](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.8...v1.0.9) (2024-04-11)

### Bug Fixes


##### Logger

* Try Json encode ([b1c293](https://github.com/liquiddesign/liquid-monitor-connector/commit/b1c293969e56f142d3ea8c481698050826b1b7fc))


---

## [1.0.8](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.7...v1.0.8) (2024-04-08)

### Bug Fixes


##### Logger

* Change code type to string ([39ddff](https://github.com/liquiddesign/liquid-monitor-connector/commit/39ddff05b25ed73e1b8565bddc8f5b82f543c9b5))


---

## [1.0.7](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.6...v1.0.7) (2024-04-08)

### Bug Fixes


##### Logger

* Change code type to string ([b59712](https://github.com/liquiddesign/liquid-monitor-connector/commit/b597122478bad88c46ddd79f3c61f943c4dab7de))


---

## [1.0.6](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.5...v1.0.6) (2024-04-07)

### Features


##### Logger

* Add code, trace to log ([0e27ff](https://github.com/liquiddesign/liquid-monitor-connector/commit/0e27ffd3e6b0a8cc69146fefa888b9c505ba137c))


---

## [1.0.5](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.4...v1.0.5) (2024-04-06)

### Features

* Add to default levels ILogger::INFO ([8c5a22](https://github.com/liquiddesign/liquid-monitor-connector/commit/8c5a22d0db400af0af5b8a093c2b0b52d179dd5b))


---

## [1.0.4](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.3...v1.0.4) (2024-03-07)


---

## [1.0.3](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.2...v1.0.3) (2024-03-07)

### Features

* Try catch requests ([0a5f03](https://github.com/liquiddesign/liquid-monitor-connector/commit/0a5f03bb16efd11bba8820bf6b6ed974c54b5e5e))


---

## [1.0.2](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.1...v1.0.2) (2024-03-07)

### Features

* New logger ([efdca2](https://github.com/liquiddesign/liquid-monitor-connector/commit/efdca2a156742390b2f87cbff5cf5662d3b3e081))


---

## [1.0.1](https://github.com/liquiddesign/liquid-monitor-connector/compare/v1.0.0...v1.0.1) (2024-03-06)

### Features

* New scheduleOrStartJob ([24c2e0](https://github.com/liquiddesign/liquid-monitor-connector/commit/24c2e069dbd4c30847a88dd37b88a302d49a28af))


---

## [1.0.0](https://github.com/liquiddesign/liquid-monitor-connector/compare/b1042e7a0e5385d745a9a5fb432394fcba9bd4c6...v1.0.0) (2024-03-01)

### Features

* Disable shutdownFunction ([4ef8b5](https://github.com/liquiddesign/liquid-monitor-connector/commit/4ef8b52089248ce5a536c62aef0df942a474b698))

### Bug Fixes

* Send json headers ([26d4be](https://github.com/liquiddesign/liquid-monitor-connector/commit/26d4be9068d573b0bbefa44749df9a837814f778))
* Timeout in json ([4793ac](https://github.com/liquiddesign/liquid-monitor-connector/commit/4793ac68bc6700ffe9547606c7574fa53a8f6c53), [fb8adb](https://github.com/liquiddesign/liquid-monitor-connector/commit/fb8adb2fba99d986c952c1fa4ad3b9bb95feffa6), [62f6bc](https://github.com/liquiddesign/liquid-monitor-connector/commit/62f6bc5007fe93d7acece10153401e61bfbb33df))


---


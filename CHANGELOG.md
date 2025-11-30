<!--- BEGIN HEADER -->
# Changelog

All notable changes to this project will be documented in this file.
<!--- END HEADER -->

## [4.1](https://github.com/prosembler/nitro-porter/compare/v4.0.1...v4.1) (2025-11-30)

### Features

* Add Docker containers ([b4e35f](https://github.com/prosembler/nitro-porter/commit/b4e35f396a5ab1f5a9934d50d14c86c16c9e9bd9))

### Bug Fixes

* Array key float in formatBytes() ([36d04e](https://github.com/prosembler/nitro-porter/commit/36d04eb265e2d29f7ae8839326d0e68792ede6ea))
* Flarum: default user import to have confirmed emails ([9efee0](https://github.com/prosembler/nitro-porter/commit/9efee0cc83cbb31f4c2841453844454b5982ae62))
* Flarum: truncate access_tokens after import ([966264](https://github.com/prosembler/nitro-porter/commit/966264f7add8dd8ee2874e6812eeeaf8755e357c))
* Use config-based table prefixes if set ([4f397e](https://github.com/prosembler/nitro-porter/commit/4f397e7c94335b44e93ba14417deda4f25520223))
* Xenforo: Export comment counts ([034782](https://github.com/prosembler/nitro-porter/commit/0347823f3ba03ee36060f17e5838e6fb5f0ce982))

---

## [4.0.1](https://github.com/prosembler/nitro-porter/compare/v4.0...v4.0.1) (2025-09-30)

### Bug Fixes

* Flarum postscript table prefix missing on post numbering ([8201f1](https://github.com/prosembler/nitro-porter/commit/8201f123c0661af7df5579775e7d52408e7eb4ac))
* Restore ability to log without echo ([870044f](https://github.com/prosembler/nitro-porter/commit/870044f38e1ee2bb3c5cebb2f2555c641a6e2180))

Also adds a few docs and lint fixes.

---

## [4.0](https://github.com/prosembler/nitro-porter/compare/v3.7.3...v4.0) (2025-09-30)

### Features

* File transfer (avatars & attachments) between Xenforo & Flarum ([6ddb77](https://github.com/prosembler/nitro-porter/commit/6ddb7750914c9b41e74b7f6bd185f3daca2b3ad2))
* Add 2-tier tag support to Flarum target ([e9bde7](https://github.com/prosembler/nitro-porter/commit/e9bde7573ecee04f82f6aa9a20f7bddaea89192a))
* Improve Flarum target logging for long steps ([e538de](https://github.com/prosembler/nitro-porter/commit/e538de1a3c6a4c540c476503e9cc43f321f0a689))
* Add a real usage example for the RUN command ([6683b8](https://github.com/prosembler/nitro-porter/commit/6683b8c1517a9d62c247c6b83105c08bd2c12c25))
* Add support for CETs in queries ([0fbbe2](https://github.com/prosembler/nitro-porter/commit/0fbbe208976b367d0105f01082aacef947bf8842))
* Allow config fallbacks for run command ([31d32b](https://github.com/prosembler/nitro-porter/commit/31d32b07b9ba6dcb16dc6799e7f412bf63c80959))
* Allow Sources to use the query builder ([89683c](https://github.com/prosembler/nitro-porter/commit/89683c19f708a07748ee63f51c8acb64fbecf132))
* Better MIME support ([00db6c](https://github.com/prosembler/nitro-porter/commit/00db6cbc710a6773db41da3c41bbdab2811f886a))
* Improve default config to display more options ([9ffc66](https://github.com/prosembler/nitro-porter/commit/9ffc660e1992995480b6bce976f0feee22c44a80))
* Phinx-based automated database tests ([aaf562](https://github.com/prosembler/nitro-porter/commit/aaf562d1cc6736eabe34a0bc22701e76a986c72e))
* Remove support for custom package options ([3b8eea](https://github.com/prosembler/nitro-porter/commit/3b8eea8282363c91575cee59406e4275330099fa))
* Require PHP 8.3 ([87f0b9](https://github.com/prosembler/nitro-porter/commit/87f0b980d0c34b7c3b9f506ef1706a05a8123557))
* Revise the example Source for modern standards ([68d49d](https://github.com/prosembler/nitro-porter/commit/68d49d33c58f055647e9060fd35435a35e1a6a9d))

### Bug Fixes

* Flarum target default role duplication on repeat run ([30849b](https://github.com/prosembler/nitro-porter/commit/30849b0e859baa2a8f74a394d2039367dc2f33a1))
* Set options to null so that an empty string is valid input ([c7b378](https://github.com/prosembler/nitro-porter/commit/c7b3788aa48df018b97e56cd22eef0be55763f34))
* Source charset table access ([51d8f9](https://github.com/prosembler/nitro-porter/commit/51d8f9e4fa6f3e93607d348431284da9c38de0eb))
* Xenforo charset table name ([ce2033](https://github.com/prosembler/nitro-porter/commit/ce20337eea5f7e1d81bf6ab1fea68fb86e2938b1))

---

## [3.7.3](https://github.com/linc/nitro-porter/compare/v3.7.2...v3.7.3) (2025-09-02)

### Bug Fixes

* Comment out intermediary structure no port is using (and may never) ([2bf657](https://github.com/linc/nitro-porter/commit/2bf65754d6d270e66d9aa40e84b6c860d374a03a))
* Flarum target partial support for fof/best-answer ([5b2293](https://github.com/linc/nitro-porter/commit/5b2293d56c8443f6a3e272062db8df6607d4d391))
* Flarum target prefix for Byobu PM table check ([69d1e9](https://github.com/linc/nitro-porter/commit/69d1e962254b7ecb0a6c8e28b2510cfeb025bf18))


---

## [3.7.2](https://github.com/linc/nitro-porter/compare/v3.7.1...v3.7.2) (2025-08-31)

### Bug Fixes

* Flarum target category import structure ([1dd9af](https://github.com/linc/nitro-porter/commit/1dd9afb20d86d78b6d33c2114d8352e3e41b17b1))
* Flarum target default MIME type on attachments ([d65774](https://github.com/linc/nitro-porter/commit/d6577412c51f6ef2c865d02dd24d295631dc8d2a))
* Resolve ctype_digit deprecation warning ([8e2dcc](https://github.com/linc/nitro-porter/commit/8e2dcceec95019e19b465189ac22b95267e928ac))


---

## [3.7.1](https://github.com/linc/nitro-porter/compare/v3.7.0...v3.7.1) (2025-08-29)

### Bug Fixes

* Flarum target filter names for preventing nulls ([fb216a](https://github.com/linc/nitro-porter/commit/fb216abaa086149d3573ad906c51bb0e17550ca6))

---

## [3.7.0](https://github.com/linc/nitro-porter/compare/v3.6.0...v3.7.0) (2025-08-25)

### Features

* License now AGPLv3 ([1cd2e1](https://github.com/linc/nitro-porter/commit/1cd2e16895832e75c2b776b0b066f6cd2e6bce54))
* Require PHP 8.2 ([c8e262](https://github.com/linc/nitro-porter/commit/c8e262da1c16c01adb982152b01e6c7490d17b73))

### Bug Fixes

* Add a small optimization to vanillaPhoto processing ([4f587e](https://github.com/linc/nitro-porter/commit/4f587e933e69a2d2baa9183f10a2f4ccc8e06fe4))
* Add clear message when PMs will be skipped because Flarum Byobu isn't on (#74) ([b1e0d2](https://github.com/linc/nitro-porter/commit/b1e0d285027d14846c1cc13d490d44dc64c57333))
* Avatars imported to Vanilla from vBulletin need the 'p' prefix ([25295d](https://github.com/linc/nitro-porter/commit/25295d1bd19137e2d65497245fe4cb5e84715776))
* Cleanup empty check for vanillPhoto() ([36457a](https://github.com/linc/nitro-porter/commit/36457a55e804c9b86d3dcb7f596b7d50d0a8084f))
* Default config debug to false ([afab2e](https://github.com/linc/nitro-porter/commit/afab2e5cf02334eb97772150c6eb956c569f382a))
* Deprecation warning in Formatter ([f236b7](https://github.com/linc/nitro-porter/commit/f236b7101cf9ad81b1c2e374f41441c0bcd0f101))
* Ensure Flarum target gets non-null SQL math values ([065fdf](https://github.com/linc/nitro-porter/commit/065fdf97170b0a5b6ee146a303e5cd3d75cbdde0))
* Flarum target non-null enforcement for discussions & tags ([69d481](https://github.com/linc/nitro-porter/commit/69d4813a00db0c560340b802b1ae2501d499f796))
* Only show deprecation notices in debug mode ([ac3d1e](https://github.com/linc/nitro-porter/commit/ac3d1eceb05e9a19ebee6e338765b3705f6dc591))
* Preserve discussion comment counts in Flarum target ([a4fcc6](https://github.com/linc/nitro-porter/commit/a4fcc64740f5f586a6c4610c58c413a40f846c6c))
* Remove duplicate discussions caused by revisions from Drupal6 source ([cfd0bd](https://github.com/linc/nitro-porter/commit/cfd0bd5efbbf21365672d254c9f52cd19ac3df74))
* Remove redundant is_array in ExportModel ([206a8d](https://github.com/linc/nitro-porter/commit/206a8dc866f2d8961b22af2000a3c804854af8be))
* Remove strict comparison in Drupal7 source to quiet PHPStan ([62b746](https://github.com/linc/nitro-porter/commit/62b746afd57ebb622a4143b2b55ce85a3b9d2d22))
* Remove timezones (str) used as hour offsets (int) ([575878](https://github.com/linc/nitro-porter/commit/575878c12efa3d6ab4ccdac54745e1a0a9f22640))
* Remove trigger_error for not coping config ([359477](https://github.com/linc/nitro-porter/commit/35947765517653d49da421daa067996799446953))
* Restore commentID to Drupal 6 source ([4b411e](https://github.com/linc/nitro-porter/commit/4b411ebc75e789f2f495dddd21aa47e0467056ec))
* Set config array defaults ([f66b4b](https://github.com/linc/nitro-porter/commit/f66b4bc191b8ec579c1cc29f29f2b365301e6052))
* Skip invalid JSON in Vanilla 'rich' comments if they cannot be fixed ([9e5a9b](https://github.com/linc/nitro-porter/commit/9e5a9bd207b7f72eda638340975f2c71a86a64e4))
* Throw exceptions in Config rather than triggering errors ([ada3f4](https://github.com/linc/nitro-porter/commit/ada3f4d06f721c241372735213aae50c5e7e0055))
* Update return type in Flarum source ([6e648a](https://github.com/linc/nitro-porter/commit/6e648a2402b67a38ed920927d4c735fc33ffdd9f))
* Use a digestable error when massive batch inserts fail ([03a3b4](https://github.com/linc/nitro-porter/commit/03a3b45a8e0db609f6f34985cac3e049780551b5))


---

## [3.6.0](https://github.com/linc/nitro-porter/compare/v3.5.2...v3.6.0) (2024-12-29)

### Features

* Update README ([4d7d590](https://github.com/linc/nitro-porter/commit/4d7d590f010c1d85b58eaffa80c380ad69f51336)) & CHANGELOG ([e2f4027](https://github.com/linc/nitro-porter/commit/e2f40272875a7cfd47ed1c08895b2c5095146489))
* Update Vanilla structure to match upstream ([0f2f3dd](https://github.com/linc/nitro-porter/pull/69/commits/0f2f3ddcf1ffda112ae07d80e16d7419fda4e521), [2ab5736](https://github.com/linc/nitro-porter/pull/69/commits/2ab57369f317ed2c0826f72194a531bd94adcf51))


### Bug Fixes

* Missing call to Vanilla Users method ([560aad](https://github.com/linc/nitro-porter/commit/560aad548dddaee1e57c5218bc8b362a8fd11caf))


---

## [3.5.2](https://github.com/linc/nitro-porter/compare/v3.5.1...v3.5.2) (2024-12-27)

### Bug Fixes

* Fix Flarum target column name error ([a6b36c](https://github.com/linc/nitro-porter/commit/a6b36cc9fa2c913571b28d92481c1ad8b99c2e96))


---

## [3.5.1](https://github.com/linc/nitro-porter/compare/v3.5.0...v3.5.1) (2024-12-27)

### Bug Fixes

* Add config error for invalid alias ([9c426d](https://github.com/linc/nitro-porter/commit/9c426df13f32460d0b9d9d02f2b36efe9cffcdc4))
* Clarify config run command help ([3c0553](https://github.com/linc/nitro-porter/commit/3c05531cd695e6cc64fc8cb466448fe74fffd786))
* Handle Vanilla avatar filename processing ([db400c](https://github.com/linc/nitro-porter/commit/db400c4bddc9ab91994fad02967554c3c9cd4f1b))


---

## [3.5.0](https://github.com/linc/nitro-porter/compare/v3.4.2...v3.5.0) (2024-12-27)

### Features

* Add config validation ([c20d9e](https://github.com/linc/nitro-porter/commit/c20d9e998bc304485a76e4e479ae5896a66f9915))


---

## [3.4.2](https://github.com/linc/nitro-porter/compare/v3.4.1...v3.4.2) (2024-12-26)

### Bug Fixes

* More prominently inform PORT_ tables may be deleted ([f2fdc7](https://github.com/linc/nitro-porter/commit/f2fdc7c9c48c5a5b4690ce1756ecda4514c336cb))
* Rename dedault target connection to 'target' ([d2df79](https://github.com/linc/nitro-porter/commit/d2df798c74b4d7a1bf84eeb45cd453d9eb40b266))
* Rename Vanilla2 source to Vanilla ([6afe61](https://github.com/linc/nitro-porter/commit/6afe61b6331f33deb4eab0a216fb40af28a47e54))
* Use the source's default database prefix ([aac125](https://github.com/linc/nitro-porter/commit/aac125ab0759cfa875c3f000af47517fb788b9d6))


---

## [3.4.1](https://github.com/linc/nitro-porter/compare/v3.4.0...v3.4.1) (2024-12-26)

### Bug Fixes

* Update autoload path to work in more scenarios ([49f22e](https://github.com/linc/nitro-porter/commit/49f22e1214131da4e1738e9ff14c5879a79eb1ab))


---

## [3.4.0](https://github.com/linc/nitro-porter/compare/v3.3.0...v3.4.0) (2024-12-26)

### Features

* Convert UI to be CLI-only ([#36](https://github.com/linc/nitro-porter/pull/36))
* Improved README ([56cf16](https://github.com/linc/nitro-porter/commit/56cf161303c7375a7945268ead5814d8fb92cce5))

### Bug Fixes

* Add alt text to README logos ([da6c31](https://github.com/linc/nitro-porter/commit/da6c313bce980cbe6b8b82f9f4faf8de43997876))
* Add context and errors to 'show' command ([e44a97](https://github.com/linc/nitro-porter/commit/e44a97664b9f66942a4b354f5d5acaf85bd50cff))
* Add missing waterhole as target ([5e27b9](https://github.com/linc/nitro-porter/commit/5e27b9bd4625f5c21a3fa1afd682c74c02021635))
* Clarify no-permission message ([fadd2a](https://github.com/linc/nitro-porter/commit/fadd2a8127bdbbe5c501b49c0eaf6a99cd2dde1e))
* Do not force [*@covers*](https://github.com/covers) annotation ([221fc3](https://github.com/linc/nitro-porter/commit/221fc355f174b08617b65d11dbef9554d3931e15))
* Filter location for phpBB ([11d7eb](https://github.com/linc/nitro-porter/commit/11d7eb4fdd3e007fa1f9951e462114adb6eddb68))
* Flarum role names cannot be null ([ac3ec6](https://github.com/linc/nitro-porter/commit/ac3ec6a231925f0a399e4592d35c4f7518304586))
* Flarum users.is_email_confirmed cannot be null ([267ea5](https://github.com/linc/nitro-porter/commit/267ea5dbb0dbce3f0fab4c5c999d54135083f2c4))
* Force Flarum tag names to not be null. ([db5d54](https://github.com/linc/nitro-porter/commit/db5d542f99a2b63d921b372ea7e8aad45574fa42))
* Prevent deletion of mapped row data ([8f3479](https://github.com/linc/nitro-porter/commit/8f3479f3bf1836c6183a37ac470ab5dd3bb692f8))
* Remove incorrect tag_id in Flarum discussion table definition ([bb55c6](https://github.com/linc/nitro-porter/commit/bb55c63db29f85c61eb34fb6bb14f7eb06112400))
* Remove utf8_encode usage and improve encoding logic ([1e28c8](https://github.com/linc/nitro-porter/commit/1e28c836abc8328d351154a43afb37c7bdb3e675))
* Remove web index ([7b41e0](https://github.com/linc/nitro-porter/commit/7b41e095bb2f15e93fdd3b92b711d11c19a93992))
* Usage examples for show command ([0b92e0](https://github.com/linc/nitro-porter/commit/0b92e0ed19b09bbda0bae751d9cb18c1c5ff6958))
* Vanilla html/wysiwyg format conversion to Flarum ([d34292](https://github.com/linc/nitro-porter/commit/d342925175c0df4e224295ad03088ccfdae29d3a))


---

## [3.3.0](https://github.com/linc/nitro-porter/compare/v3.2.0...v3.3.0) (2023-04-23)

### Features

* Add support for IPB4. ([28f69b](28f69bd17b0cab9b18eb753f9107e934eb1ae7d1))
* Allow run without config.php and give notice. ([70e268](https://github.com/linc/nitro-porter/commit/70e268bb4fd58df4752e5616970326aac088aa4e))
* Condense global src/target logging format. ([b9b053](https://github.com/linc/nitro-porter/commit/b9b0535ce544e0c8f680ecd21a27c859fd55dff0))
* Make 'no permission' warning global. ([0d5f56](https://github.com/linc/nitro-porter/commit/0d5f5668dd419575e904cd1f8f684d390e0e222a))
* Add a bunch of logging to core. ([a4003d](https://github.com/linc/nitro-porter/commit/a4003da2e02c0178b195a701fbc12bf5346648fa))
* Add clarity and data checks to Flarum target. ([86e05e](https://github.com/linc/nitro-porter/commit/86e05eefd1da7ab2642bd6fd4df4bc6a0c49e80f))

### Bug Fixes

* Fix limit for Xenforo PM subject lines. ([ab9b03](https://github.com/linc/nitro-porter/commit/ab9b0312cdbdc883c22814a7c9ac6dcd51dc5019))
* Enforce primary key on Flarum discussions.id (fixes post numbering performance). ([bf20d1](https://github.com/linc/nitro-porter/commit/bf20d1c99f4fec08ac4a61d9f4fb23190564cd87))
* Fix Flarum Postscript for migrations without discussion read records. ([f37b0f](https://github.com/linc/nitro-porter/commit/f37b0f85bc4b7f09e84976eb7d31a1fb9da3ce6f))
* Fix ASP comment body transfer. ([9fda19](https://github.com/linc/nitro-porter/commit/9fda192fcb4005fcdbaedd9b8f54e945d0c540b9)) and cleanup formatting. ([0953ac](https://github.com/linc/nitro-porter/commit/0953acd737ebf76b0dbbdc4aa5136fc8e3b9ffbe))
* Modify Flarum target to always output role tables. ([73c717](https://github.com/linc/nitro-porter/commit/73c717b1d63ccaaeffcf52a00fc7df0d359f3c1d))
* Gate MySQL-only optimizations. ([6a037a](https://github.com/linc/nitro-porter/commit/6a037a0a430ace0d18ce322945a1f986dd07d140))
* Improve old db exists() function to do what you think it does. ([198ae7](https://github.com/linc/nitro-porter/commit/198ae728e6d7b012cb7872e0279515b20b01134a))
* Reach PHPStan level 5 by fixing param typing. ([b81182](https://github.com/linc/nitro-porter/commit/b811821ccecdf9e4029793224dcf765fd0c58fc9))

---

## [3.2.0](https://github.com/linc/nitro-porter/compare/v3.1.2...v3.2.0) (2022-12-10)

* Improve CLI output with progress logs and valid parameter values. ([42e942](https://github.com/linc/nitro-porter/commit/42e9426ca8fc9bafb6c598fed2ca881aa603b178))
* Fix CLI request handling. ([0193ea](https://github.com/linc/nitro-porter/commit/0193ea33f57c81078a888c479c956b43587a13d6))
* Fix case-sensitive detection of PDO class.

---

## [3.1.2](https://github.com/linc/nitro-porter/compare/v3.1.1...v3.1.2) (2022-10-23)

* Fix 'Wysiwyg' and 'Html' formatting from Vanilla to Flarum.

---

## [3.1.1](https://github.com/linc/nitro-porter/compare/v3.1...v3.1.1) (2022-10-22)

* Fix routing error preventing main process from running.

---

## [3.1](https://github.com/linc/nitro-porter/compare/v3.1...v3.1) (2022-10-22)

* Add this changelog.
* Fix avatar locations in Flarum target support.
* Add Spotify embed support for Flarum.
* Add discussion view count support for Flarum.
* Fix performance and other issues importing to blank database for Flarum.
* Fix support for "My media" page in Flarum.
* Fix Kunena source support (thanks to specialworld83)
* Improve feature table to not show 'x' when the feature isn't available.
* Add notes on Flarum and Vanilla feature compatibility.
* Add built-in support pages for Target platform features.

---

## [3.0](https://github.com/linc/nitro-porter/compare/v2.5...v3.0) (2022-09-27)

* Rebuilds entire framework and adds Illuminate database driver.
* Adds target support for Flarum.
* Adds source support for Flarum.
* Adds source support for Vanilla's Rich Text Editor.
* Adds source support for MVC.
* Adds source support for Q2A.
* Fixes multiple minor bugs in source packages.

---

## [2.5](https://github.com/linc/nitro-porter/compare/v2.4...v2.5) (2019-10-25)


---

## [2.4](https://github.com/linc/nitro-porter/compare/v2.3...v2.4) (2018-09-06)


---

## [2.3](https://github.com/linc/nitro-porter/compare/v2.2...v2.3) (2016-06-17)


---

## [2.2](https://github.com/linc/nitro-porter/compare/v2.1.3...v2.2) (2015-06-09)


---

## [2.1.3](https://github.com/linc/nitro-porter/compare/v2.1.2...v2.1.3) (2014-12-06)


---

## [2.1.2](https://github.com/linc/nitro-porter/compare/v2.1.1...v2.1.2) (2014-12-06)


---

## [2.1.1](https://github.com/linc/nitro-porter/compare/v2.1...v2.1.1) (2014-12-05)


---

## [2.1](https://github.com/linc/nitro-porter/compare/v1.3.1...v2.1) (2014-11-22)


---

## [1.3.1](https://github.com/linc/nitro-porter/compare/v1.3.0...v1.3.1) (2010-11-14)


---

## [1.3.0](https://github.com/linc/nitro-porter/compare/v1.2.0...v1.3.0) (2010-11-09)


---

## [1.2.0](https://github.com/linc/nitro-porter/compare/v1.1.2...v1.2.0) (2010-09-08)


---

## [1.1.2](https://github.com/linc/nitro-porter/compare/v1.1...v1.1.2) (2010-09-07)


---

## [1.1](https://github.com/linc/nitro-porter/compare/v1.0.1...v1.1) (2010-07-31)


---

## [1.0.1](https://github.com/linc/nitro-porter/compare/v1.0...v1.0.1) (2010-07-22)


---

## [1.0](https://github.com/linc/nitro-porter/compare/40b55d3a8d29db78aa798d0405270266aacc7972...v1.0) (2010-07-21)


---


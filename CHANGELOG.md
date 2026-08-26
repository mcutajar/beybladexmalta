# Changelog

Every released version, grouped by what its commits said they were doing.
Generated from the commit history by [git-cliff](https://git-cliff.org):
`make release` rewrites it as part of cutting a version, and `make changelog`
does it on demand. It is not edited by hand.

## [1.2.0](https://github.com/mcutajar/beybladexmalta/releases/tag/v1.2.0) - 2026-08-26

### Features

- Check the Challonge module route before reading a bracket ([261b11c](https://github.com/mcutajar/beybladexmalta/commit/261b11c13cb96c28bb4c7f92c000c132c442e0cb))
- Run the Challonge route check on its own and on a schedule ([8f2c509](https://github.com/mcutajar/beybladexmalta/commit/8f2c5091323978720b086ac550b4b259077a0675))
- Fold a Challonge spelling to the name underneath it ([c1d292a](https://github.com/mcutajar/beybladexmalta/commit/c1d292a58fbf96706ed5b6a44bb590ebb03147ed))
- Resolve a display name to a blader, or to a question ([4a282b4](https://github.com/mcutajar/beybladexmalta/commit/4a282b4959b9cf907d9e5eed32d8dcd79bff9694))
- Record which spelling belongs to which blader from the shell ([8cc957e](https://github.com/mcutajar/beybladexmalta/commit/8cc957e4d39fb969a2ef45be6a9aba10a2564d5b))
- Read the alias table out of the tournaments already imported ([15f5212](https://github.com/mcutajar/beybladexmalta/commit/15f5212380a5e5fa6d476bfba157595d78ae9b01))
- Seed the fifteen aliases the sixteen imported events agree on ([1be7068](https://github.com/mcutajar/beybladexmalta/commit/1be7068a110708f6cdcdffdb15ffe8ba7075d829))

### Fixes

- Check the fields a match is actually read through ([39751f2](https://github.com/mcutajar/beybladexmalta/commit/39751f299366b2a7d0eefd7b6e2e2cf4b73894c6))
- Tell a Challonge we could not reach from one that changed ([ef7f2b6](https://github.com/mcutajar/beybladexmalta/commit/ef7f2b6c1bd31ae62d11bd68d0c594ad664c26f9))
- Refuse a spelling that reaches two bladers instead of picking one ([6e05cd7](https://github.com/mcutajar/beybladexmalta/commit/6e05cd7fa1a3e6cf067d8aab975fe1564f6e255d))
- Two silent guards, and a ledger failure that reported the wrong fact ([111de32](https://github.com/mcutajar/beybladexmalta/commit/111de325717bbe51ba61cff24dc0e20a558968ed))

### Documentation

- Record where the Challonge smoke check sits ([facb2a1](https://github.com/mcutajar/beybladexmalta/commit/facb2a182a83b73efc15a9096b69292026617ad0))
- Record the alias table and the rule underneath it ([29ba714](https://github.com/mcutajar/beybladexmalta/commit/29ba71423ec8ce9020fcb447da0127ed9a6f9640))
- Record the phantom rule, and that the alias table was derived ([b4e9a62](https://github.com/mcutajar/beybladexmalta/commit/b4e9a6237bec91ab09f37870ef01bbc332af38ce))

### Testing

- Stop a bootstrap test depending on a bracket staying uncaptured ([4d39c04](https://github.com/mcutajar/beybladexmalta/commit/4d39c04496ebddc78801ad34e04cfe6efbd3d542))

### Maintenance

- Report coverage in the job summary instead of a pull request comment ([38a7f2c](https://github.com/mcutajar/beybladexmalta/commit/38a7f2c8e86e11f0edee71c2be71f78c31c7a17a))

## [1.1.0](https://github.com/mcutajar/beybladexmalta/releases/tag/v1.1.0) - 2026-08-25

### Features

- Capture a Challonge bracket to a snapshot file ([7e67ca3](https://github.com/mcutajar/beybladexmalta/commit/7e67ca3f11288d39b125de58771b460ab6531160))
- Read a Challonge snapshot back and join its standings ([96f790d](https://github.com/mcutajar/beybladexmalta/commit/96f790da9da173b8e173035ac6f60b7d30818844))
- Generate the changelog from the commit history ([86deae3](https://github.com/mcutajar/beybladexmalta/commit/86deae394165d1cac79dfaac3cce60622f0b6fb2))
- Render the release notes from the commits, not pull request titles ([b345dda](https://github.com/mcutajar/beybladexmalta/commit/b345dda1f5c835e7ea4984646151b5a0e8ae6b31))

### Fixes

- Refuse a Challonge field that has changed type ([0942fe9](https://github.com/mcutajar/beybladexmalta/commit/0942fe989772ca9ec4daa123024fa7d83bd9e1cb))
- Close the gaps the review found in the reader and the join ([ffade34](https://github.com/mcutajar/beybladexmalta/commit/ffade34d110460c02d77dfb37af4056d9a2d667d))

### Documentation

- Say why the team events are named rather than detected ([89d2079](https://github.com/mcutajar/beybladexmalta/commit/89d2079a49f535c4f2083b6cc0cc589f03dc6e2a))
- Record where the changelog comes from ([fb622c9](https://github.com/mcutajar/beybladexmalta/commit/fb622c9968678351904077c85b5608bb8d6a38ff))

### Maintenance

- Give Dependabot's commits the deps scope ([652222f](https://github.com/mcutajar/beybladexmalta/commit/652222f6971d652a78d49d57ad0562e7b39f801b))

## [1.0.0](https://github.com/mcutajar/beybladexmalta/releases/tag/v1.0.0) - 2026-08-24

### Features

- Initial run ([cc01320](https://github.com/mcutajar/beybladexmalta/commit/cc01320ca41a53313305043322b153f0f4bc5c48))
- Include tailwind and some compose adjustments ([e24e85c](https://github.com/mcutajar/beybladexmalta/commit/e24e85c31e024e328e62b2a67f751376dd65cd77))
- Include tailwind and main twig page ([35ee2ec](https://github.com/mcutajar/beybladexmalta/commit/35ee2ec6c999b6641a9748149eb9bcae3d957219))
- .gitignore ([33c27b5](https://github.com/mcutajar/beybladexmalta/commit/33c27b5f245f28a00fb2e9efd9a5e2b58e2527f2))
- .gitignore ([ace3497](https://github.com/mcutajar/beybladexmalta/commit/ace34978062de64ed422881307867fc901201f6b))
- Add proposal-v2.html.twig for league framework ([4187dee](https://github.com/mcutajar/beybladexmalta/commit/4187dee025e4a475afae632837ce0bfd2224aa00))
- Add prod mode and instructions to launch prod ([37cb356](https://github.com/mcutajar/beybladexmalta/commit/37cb3568d29072950a1ccbeb5e608b39a00df472))
- Some amendments to the new proposal page ([a87e80f](https://github.com/mcutajar/beybladexmalta/commit/a87e80f150b277d9f3e6a232988d6dfbc594caf0))
- Add blader list section ([d5e0dea](https://github.com/mcutajar/beybladexmalta/commit/d5e0dea0c8fa0b6a7c7d742db450f92f6080a3c2))
- Add changes from latest discussions ([8ddb6fc](https://github.com/mcutajar/beybladexmalta/commit/8ddb6fc17d389716ec4998050820f311c00f2595))
- Updated preseason page with a database and added import command ([b88b494](https://github.com/mcutajar/beybladexmalta/commit/b88b494df4d43896227f4ff117556e890b5e35d9))
- Add new tournament results ([5f16b2a](https://github.com/mcutajar/beybladexmalta/commit/5f16b2a8c7b40f90173c37fd04da58757427eac4))
- Add tournament details and player details pages ([5228a1e](https://github.com/mcutajar/beybladexmalta/commit/5228a1ebc22b07d6ebba3e4aa80a0ad28b42c10c))
- Add seasons and season registration support ([983edec](https://github.com/mcutajar/beybladexmalta/commit/983edec062e8f1fdd92ec2d7f7b1b62ae1012d83))
- Add CreateSeasonCommand and add simple "event source" for backups ([336467c](https://github.com/mcutajar/beybladexmalta/commit/336467c022e3f1c9d9b27db3c64272784ce43bbf))
- Implement basic event source and a way to rebuild the data from scratch ([381e7f1](https://github.com/mcutajar/beybladexmalta/commit/381e7f12552171bfb09cb8beedec884a9bac9123))
- Update history ([b6243f5](https://github.com/mcutajar/beybladexmalta/commit/b6243f57fbc7cac0119998841af714feeee73d08))
- Update repeat.sh and add imports history ([4085398](https://github.com/mcutajar/beybladexmalta/commit/40853986bc16532bbf5a5d9282d192a1e04aa1de))
- Include new results ([39e1ea0](https://github.com/mcutajar/beybladexmalta/commit/39e1ea0bbb78879566ef9a11739149f4982133c7))
- Registered player payment ([1a6456b](https://github.com/mcutajar/beybladexmalta/commit/1a6456bd119174f08ff54ab9250c7889bd35deb5))
- Update history ([a516d40](https://github.com/mcutajar/beybladexmalta/commit/a516d4070cd2b07ce1e3c9b3e1892f5c19bc39d4))
- Add data ([7500da2](https://github.com/mcutajar/beybladexmalta/commit/7500da2a3ac36b4d6ad6c4e4ae4efda805b1060a))
- Update imports ([45ab457](https://github.com/mcutajar/beybladexmalta/commit/45ab4574df089f431fba4dba5e7d7d5fedcab60e))
- Add new tournaments and adjustments ([44edd94](https://github.com/mcutajar/beybladexmalta/commit/44edd94e6e4b80bd39075ccd4166d0a11db27514))
- Some docker-compose adjustments ([e5970e0](https://github.com/mcutajar/beybladexmalta/commit/e5970e01e0e2a6551f9b80b8d5b12372bc7f386a))
- Php-cs-fixer ([4e1fdb4](https://github.com/mcutajar/beybladexmalta/commit/4e1fdb40085b9086397658774306ad9c55e66d89))
- Require --dev symfony/test-pack ([1ee2620](https://github.com/mcutajar/beybladexmalta/commit/1ee26206da97eaea2b08b708ed1586e2e661e39a))
- Add some basic factories/stories and tests ([fecdf67](https://github.com/mcutajar/beybladexmalta/commit/fecdf67e9640cee16423c65974eaa8b07d37d9e0))
- Separate concerns for the LeagueRegistrationController action ([3eb33dd](https://github.com/mcutajar/beybladexmalta/commit/3eb33ddd182eaf7180b81e7f7545d64402bac07a))
- Split the dynamic form into it's own type ([e975c91](https://github.com/mcutajar/beybladexmalta/commit/e975c91efcc25b9b6d7c30a5eacc630c63f060e5))
- Declare strict types and enforce it with fixer ([e11b489](https://github.com/mcutajar/beybladexmalta/commit/e11b4896ffae663ae8bf5de46fb12ced6fe0d481))
- Align register payment command to controller ([1aa3daf](https://github.com/mcutajar/beybladexmalta/commit/1aa3dafc985f4c64e6dbcd01064446430dbdd466))
- Extract the tournament import into a service layer ([5e5145e](https://github.com/mcutajar/beybladexmalta/commit/5e5145e1cc924dcd884dcd51accbe7fb5b73dd7b))
- Add PHPStan at level 6 and wire it into make check ([a3d0715](https://github.com/mcutajar/beybladexmalta/commit/a3d071542acc37c0d10b5be075b27b1befb2b90a))
- Update ledger ([6a7ff65](https://github.com/mcutajar/beybladexmalta/commit/6a7ff656aced77275a0f34b619d7557f63bd698e))
- Bootstrap and rebuild the dev database from repeat.sh ([07b25f3](https://github.com/mcutajar/beybladexmalta/commit/07b25f367fb1d2fa7893b52e2060083eaac31087))
- Report test coverage on every pull request ([119e96f](https://github.com/mcutajar/beybladexmalta/commit/119e96fda45166ebf0ce629cfcef676ef7e51478))
- Put the coverage percentage on a README badge ([a73e515](https://github.com/mcutajar/beybladexmalta/commit/a73e515e4c3328281ae0ebbd23cda03fce98104f))
- Share one footer across every template, carrying the source link ([3428ebf](https://github.com/mcutajar/beybladexmalta/commit/3428ebf336eb903e50bc086615db41fcf0ece2ce))
- Give the site a design system instead of copy-pasted utilities ([cddf8e1](https://github.com/mcutajar/beybladexmalta/commit/cddf8e1e9d832cc5035da0bf774f1fc80e3072ed))
- Fix mistake in import of 05 July ([f10632c](https://github.com/mcutajar/beybladexmalta/commit/f10632ca99173c79aa7e70d82126afdc4cb8b76e))
- Publish a versioned image per release tag ([3a59967](https://github.com/mcutajar/beybladexmalta/commit/3a599671dbdc5b33a9c6d5c4e4f5f62b28a4d306))
- Add latest data from imports ([22ae4e5](https://github.com/mcutajar/beybladexmalta/commit/22ae4e554fe003e19619c31fe6d6055cecd3aad3))

### Fixes

- Fixed small issue in point allocation table ([dcf9853](https://github.com/mcutajar/beybladexmalta/commit/dcf9853025b3641daaa1c7b7f6cb2ac98b01ba41))
- Resolve issue with .env.local and update repeat.sh ([217d312](https://github.com/mcutajar/beybladexmalta/commit/217d312a9cbe87274667f849334dc53317184403))
- Fixed small issue in the tournament result repository ([d89f1b2](https://github.com/mcutajar/beybladexmalta/commit/d89f1b209c2fa234a2f020411feb78cb3c8d89d4))
- Keep the ledger and the database in step ([9735b60](https://github.com/mcutajar/beybladexmalta/commit/9735b608b1ba06da7c527f7fc7ef38c892e8dcc1))
- Stop committing the built Tailwind stylesheet ([6dce707](https://github.com/mcutajar/beybladexmalta/commit/6dce707142ea5fc0ab946dfbf5dadcd74ede09cd))
- Let a worktree stack override the published database port ([2198128](https://github.com/mcutajar/beybladexmalta/commit/21981283c5997c133305ad38941798700fdf61ce))
- Layer .env under .env.local when driving Compose ([cd3ba34](https://github.com/mcutajar/beybladexmalta/commit/cd3ba348f6d93fa5b1f10d2ff4e72bddcf64dc36))
- Create var/log before appending to the ledger ([e35cdec](https://github.com/mcutajar/beybladexmalta/commit/e35cdecd8b24c089c10119e70bdb57b87d06c6aa))
- Call the community by its own name, and drop the copyright line ([dcea061](https://github.com/mcutajar/beybladexmalta/commit/dcea061f2d3e2e294d35588040f59a8f637323b2))
- Stop calling the live standings a simulation ([4962d20](https://github.com/mcutajar/beybladexmalta/commit/4962d2001fc9d422c3bb55ef1c19121ed0a57d14))
- Stop production serving a build it was not given ([0244ea2](https://github.com/mcutajar/beybladexmalta/commit/0244ea2fe4b4d83414c02286b793b57004bc2c10))
- Stop the dev stack from replacing production ([f48a17f](https://github.com/mcutajar/beybladexmalta/commit/f48a17f03d0f027f4c56b3f0f5d4df5f3e8ddd89))
- Refuse an admin passphrase that was never configured ([3b6f270](https://github.com/mcutajar/beybladexmalta/commit/3b6f270ebd2fc7cd0819bb703fc12c4ab8877f57))
- Cut releases from the main checkout, not a worktree ([0b75397](https://github.com/mcutajar/beybladexmalta/commit/0b75397f92ac6a0eabcaa3a4e616a1ed055779e2))

### Refactoring

- Minor wording change ([96b6211](https://github.com/mcutajar/beybladexmalta/commit/96b6211da76886aef049d71b50679e3875d2e310))
- Minor wording change ([9ce15a1](https://github.com/mcutajar/beybladexmalta/commit/9ce15a1c1f08678eb6258bc7e7222cdebe484a52))
- Route CreateSeasonCommand's ledger write through LedgerService ([2b3599a](https://github.com/mcutajar/beybladexmalta/commit/2b3599a2b7d05d429fd6695fb71364b42fc0d5ce))
- Move the Foundry factories and stories under tests/ ([80491ad](https://github.com/mcutajar/beybladexmalta/commit/80491ade1eb90dc677517b822c3cf77a2e041ef8))

### Documentation

- Update readme ([4a60d7a](https://github.com/mcutajar/beybladexmalta/commit/4a60d7add74af66e2551d00feb23f58f8bb6f68f))
- Warn about the composer race on a fresh make up ([a68b675](https://github.com/mcutajar/beybladexmalta/commit/a68b675cb49ea59fb8484aab9f8d3df48d28e14e))
- Correct the worktree advice and add four more gotchas ([204f6ed](https://github.com/mcutajar/beybladexmalta/commit/204f6ed4a2fdb6656fb8de14350b0d19fae2de24))
- Correct the gh note, it is installed but off the PATH ([e70fcb0](https://github.com/mcutajar/beybladexmalta/commit/e70fcb06769e4100566627939c158d0fea38bbf5))
- Gh resolves bare now, note the symlink instead of the workaround ([ae55ff5](https://github.com/mcutajar/beybladexmalta/commit/ae55ff535f07203d5f2b7f439bf0095c0e5e2460))
- Record the test support layer and the DB_PORT override ([0d9cd8d](https://github.com/mcutajar/beybladexmalta/commit/0d9cd8de7841d48d8af934ce0fbe9b6fd312e48f))
- Write down how a design proposal is put together ([15d0a66](https://github.com/mcutajar/beybladexmalta/commit/15d0a669c81f50b8f1cba25cc14bc4be2c8049f6))
- A proposal's catalogue starts from the components we already have ([e209cd2](https://github.com/mcutajar/beybladexmalta/commit/e209cd2ea625481889e1a6d360a112030cb483dd))
- The component catalogue is its own document, not a proposal section ([11a07a2](https://github.com/mcutajar/beybladexmalta/commit/11a07a29580f33b1b4a8bd3e14bcd34af945563e))
- The proposed component library is fiction, /_styleguide is not ([5313ddd](https://github.com/mcutajar/beybladexmalta/commit/5313ddd5762eda1875ce1d05c0c9ec4a9266930d))
- Bring the README back in line with how the stack is actually run ([bf3e33f](https://github.com/mcutajar/beybladexmalta/commit/bf3e33fe2a48b4d8f3d70f9667bb193a77a59496))
- Describe how a release is cut, deployed and rolled back ([c10f6ba](https://github.com/mcutajar/beybladexmalta/commit/c10f6bacc725706ee586831fc1c8e14e2a87f900))

### Testing

- Extract the shared plumbing into tests/Support ([03b6757](https://github.com/mcutajar/beybladexmalta/commit/03b6757007acbd0edfd4dc45ebf0d12a3d20c44b))
- Cover the legacy season aliases ([898a2bb](https://github.com/mcutajar/beybladexmalta/commit/898a2bb2de2ccc128a45f2c97ec6613e8f1ab86e))

### Maintenance

- Document the container-first workflow for agents ([9961ddc](https://github.com/mcutajar/beybladexmalta/commit/9961ddc1788463a5f4bdc0916af7d6c276226354))
- Run the test suite on every pull request ([1deb53a](https://github.com/mcutajar/beybladexmalta/commit/1deb53af78dd175d4fe00c9f4ea6e3f6a9f34ea5))
- Stop tracking the generated config reference ([95574bf](https://github.com/mcutajar/beybladexmalta/commit/95574bfd21a4630b2f4fc0d07fd56c9a2528320a))
- Relicense the project under AGPL-3.0 ([164c11a](https://github.com/mcutajar/beybladexmalta/commit/164c11a9c1d776a8537a5d3b907bb5721eeb5b3b))

### Other

- Initial commit ([04708cd](https://github.com/mcutajar/beybladexmalta/commit/04708cd1a2802da61b6a6d83b275824d381c2150))
- Some additions from copilot but then stopped using it ([6cfac85](https://github.com/mcutajar/beybladexmalta/commit/6cfac85323f299a8c70a35aa7c975f7d6456d96e))


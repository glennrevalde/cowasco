-- =====================================================================
--  Water billing starter lists (no consumers, no transactions)
--  Rate codes, pipe sizes and the progressive tariff tiers per rate code and pipe size, discount classes,
--  address types, contact channels, cities, and the default branch / division / segment.
--  Taken from the set-up of the former water program, without its test rows. Loaded by tools/water_starter.php
--  (which also makes the water accounts, bill codes and settings). MySQL 5.5 compatible.
--  The tariff is a starting point: review it on Water Billing > Rates & Discounts before the first bill.
--  Senior Citizen: 5% off the water bill (RA 9994; the program applies it up to 30 cubic metres a month), at most
--  100.00 a bill (a percentage discount needs a ceiling; set the cooperative's own on Discount Management).
-- =====================================================================

-- addresstype (3 rows)
INSERT INTO `addresstype` (`pkey`, `name`, `description`, `status`) VALUES
(1, 'Residence', 'Residence Address', 'A'),
(2, 'Provincial', 'Provincial Address', 'A'),
(3, 'Office', 'Office/Business Address', 'A');

-- contactchannels (4 rows)
INSERT INTO `contactchannels` (`pkey`, `name`, `description`, `datecreated`, `datelastmodified`, `status`) VALUES
(1, 'Telephone', 'Tel. no.', '2013-01-21 22:23:23', NULL, 'A'),
(2, 'Mobile', 'Mobile no.', '2013-01-21 22:23:23', NULL, 'A'),
(3, 'Office', 'Tel. no.', '2013-01-21 22:23:23', NULL, 'A'),
(4, 'E-mail', 'E-mail Address', '2013-01-21 22:23:23', NULL, 'A');

-- cities (145 rows)
INSERT INTO `cities` (`pkey`, `city`, `province`, `country`, `status`) VALUES
(1, 'Caloocan', '', 'Philippines', 'A'),
(2, 'Las Piñas', '', 'Philippines', 'A'),
(3, 'Makati', '', 'Philippines', 'A'),
(4, 'Malabon', '', 'Philippines', 'A'),
(5, 'Mandaluyong', '', 'Philippines', 'A'),
(6, 'Manila', '', 'Philippines', 'A'),
(7, 'Marikina', '', 'Philippines', 'A'),
(8, 'Muntinlupa', '', 'Philippines', 'A'),
(9, 'Navotas', '', 'Philippines', 'A'),
(10, 'Parañaque', '', 'Philippines', 'A'),
(11, 'Pasay', '', 'Philippines', 'A'),
(12, 'Pasig', '', 'Philippines', 'A'),
(13, 'Quezon City', '', 'Philippines', 'A'),
(14, 'San Juan', '', 'Philippines', 'A'),
(15, 'Taguig', '', 'Philippines', 'A'),
(16, 'Valenzuela', '', 'Philippines', 'A'),
(17, 'Butuan', 'Agusan del Norte', 'Philippines', 'A'),
(18, 'Cabadbaran', 'Agusan del Norte', 'Philippines', 'A'),
(19, 'Bayugan', 'Agusan del Sur', 'Philippines', 'A'),
(20, 'Legazpi', 'Albay', 'Philippines', 'A'),
(21, 'Ligao', 'Albay', 'Philippines', 'A'),
(22, 'Tabaco', 'Albay', 'Philippines', 'A'),
(23, 'Isabela', 'Basilan', 'Philippines', 'A'),
(24, 'Lamitan', 'Basilan', 'Philippines', 'A'),
(25, 'Balanga', 'Bataan', 'Philippines', 'A'),
(26, 'Batangas City', 'Batangas', 'Philippines', 'A'),
(27, 'Lipa', 'Batangas', 'Philippines', 'A'),
(28, 'Tanauan', 'Batangas', 'Philippines', 'A'),
(29, 'Baguio', 'Benguet', 'Philippines', 'A'),
(30, 'Tagbilaran', 'Bohol', 'Philippines', 'A'),
(31, 'Malaybalay', 'Bukidnon', 'Philippines', 'A'),
(32, 'Valencia', 'Bukidnon', 'Philippines', 'A'),
(33, 'Malolos', 'Bulacan', 'Philippines', 'A'),
(34, 'Meycauayan', 'Bulacan', 'Philippines', 'A'),
(35, 'San Jose del Monte', 'Bulacan', 'Philippines', 'A'),
(36, 'Tuguegarao', 'Cagayan', 'Philippines', 'A'),
(37, 'Iriga', 'Camarines Sur', 'Philippines', 'A'),
(38, 'Naga', 'Camarines Sur', 'Philippines', 'A'),
(39, 'Roxas', 'Capiz', 'Philippines', 'A'),
(40, 'Bacoor', 'Cavite', 'Philippines', 'A'),
(41, 'Cavite City', 'Cavite', 'Philippines', 'A'),
(42, 'Dasmariñas', 'Cavite', 'Philippines', 'A'),
(43, 'Imus', 'Cavite', 'Philippines', 'A'),
(44, 'Tagaytay', 'Cavite', 'Philippines', 'A'),
(45, 'Trece Martires', 'Cavite', 'Philippines', 'A'),
(46, 'Bogo', 'Cebu', 'Philippines', 'A'),
(47, 'Carcar', 'Cebu', 'Philippines', 'A'),
(48, 'Cebu City', 'Cebu', 'Philippines', 'A'),
(49, 'Danao', 'Cebu', 'Philippines', 'A'),
(50, 'Lapu-Lapu', 'Cebu', 'Philippines', 'A'),
(51, 'Mandaue', 'Cebu', 'Philippines', 'A'),
(52, 'Naga', 'Cebu', 'Philippines', 'A'),
(53, 'Talisay', 'Cebu', 'Philippines', 'A'),
(54, 'Toledo', 'Cebu', 'Philippines', 'A'),
(55, 'Kidapawan', 'Cotabato (North)', 'Philippines', 'A'),
(56, 'Panabo', 'Davao del Norte', 'Philippines', 'A'),
(57, 'Samal', 'Davao del Norte', 'Philippines', 'A'),
(58, 'Tagum', 'Davao del Norte', 'Philippines', 'A'),
(59, 'Davao City', 'Davao del Sur', 'Philippines', 'A'),
(60, 'Digos', 'Davao del Sur', 'Philippines', 'A'),
(61, 'Mati', 'Davao Oriental', 'Philippines', 'A'),
(62, 'Borongan', 'Eastern Samar', 'Philippines', 'A'),
(63, 'Batac', 'Ilocos Norte', 'Philippines', 'A'),
(64, 'Laoag', 'Ilocos Norte', 'Philippines', 'A'),
(65, 'Candon', 'Ilocos Sur', 'Philippines', 'A'),
(66, 'Vigan', 'Ilocos Sur', 'Philippines', 'A'),
(67, 'Iloilo City', 'Iloilo', 'Philippines', 'A'),
(68, 'Passi', 'Iloilo', 'Philippines', 'A'),
(69, 'Cauayan', 'Isabela', 'Philippines', 'A'),
(70, 'Ilagan', 'Isabela', 'Philippines', 'A'),
(71, 'Santiago', 'Isabela', 'Philippines', 'A'),
(72, 'Tabuk', 'Kalinga', 'Philippines', 'A'),
(73, 'San Fernando', 'La Union', 'Philippines', 'A'),
(74, 'Biñan', 'Laguna', 'Philippines', 'A'),
(75, 'Cabuyao', 'Laguna', 'Philippines', 'A'),
(76, 'Calamba', 'Laguna', 'Philippines', 'A'),
(77, 'San Pablo', 'Laguna', 'Philippines', 'A'),
(78, 'Santa Rosa', 'Laguna', 'Philippines', 'A'),
(79, 'Iligan', 'Lanao del Norte', 'Philippines', 'A'),
(80, 'Marawi', 'Lanao del Sur', 'Philippines', 'A'),
(81, 'Baybay', 'Leyte', 'Philippines', 'A'),
(82, 'Ormoc', 'Leyte', 'Philippines', 'A'),
(83, 'Tacloban', 'Leyte', 'Philippines', 'A'),
(84, 'Cotabato City', 'Maguindanao', 'Philippines', 'A'),
(85, 'Masbate City', 'Masbate', 'Philippines', 'A'),
(86, 'Oroquieta', 'Misamis Occidental', 'Philippines', 'A'),
(87, 'Ozamiz', 'Misamis Occidental', 'Philippines', 'A'),
(88, 'Tangub', 'Misamis Occidental', 'Philippines', 'A'),
(89, 'Cagayan de Oro', 'Misamis Oriental', 'Philippines', 'A'),
(90, 'El Salvador', 'Misamis Oriental', 'Philippines', 'A'),
(91, 'Gingoog', 'Misamis Oriental', 'Philippines', 'A'),
(92, 'Bacolod', 'Negros Occidental', 'Philippines', 'A'),
(93, 'Bago', 'Negros Occidental', 'Philippines', 'A'),
(94, 'Cadiz', 'Negros Occidental', 'Philippines', 'A'),
(95, 'Escalante', 'Negros Occidental', 'Philippines', 'A'),
(96, 'Himamaylan', 'Negros Occidental', 'Philippines', 'A'),
(97, 'Kabankalan', 'Negros Occidental', 'Philippines', 'A'),
(98, 'La Carlota', 'Negros Occidental', 'Philippines', 'A'),
(99, 'Sagay', 'Negros Occidental', 'Philippines', 'A'),
(100, 'San Carlos', 'Negros Occidental', 'Philippines', 'A');
INSERT INTO `cities` (`pkey`, `city`, `province`, `country`, `status`) VALUES
(101, 'Silay', 'Negros Occidental', 'Philippines', 'A'),
(102, 'Sipalay', 'Negros Occidental', 'Philippines', 'A'),
(103, 'Talisay', 'Negros Occidental', 'Philippines', 'A'),
(104, 'Victorias', 'Negros Occidental', 'Philippines', 'A'),
(105, 'Bais', 'Negros Oriental', 'Philippines', 'A'),
(106, 'Bayawan', 'Negros Oriental', 'Philippines', 'A'),
(107, 'Canlaon', 'Negros Oriental', 'Philippines', 'A'),
(108, 'Dumaguete', 'Negros Oriental', 'Philippines', 'A'),
(109, 'Guihulngan', 'Negros Oriental', 'Philippines', 'A'),
(110, 'Tanjay', 'Negros Oriental', 'Philippines', 'A'),
(111, 'Cabanatuan', 'Nueva Ecija', 'Philippines', 'A'),
(112, 'Gapan', 'Nueva Ecija', 'Philippines', 'A'),
(113, 'Muñoz', 'Nueva Ecija', 'Philippines', 'A'),
(114, 'Palayan', 'Nueva Ecija', 'Philippines', 'A'),
(115, 'San Jose', 'Nueva Ecija', 'Philippines', 'A'),
(116, 'Calapan', 'Oriental Mindoro', 'Philippines', 'A'),
(117, 'Puerto Princesa', 'Palawan', 'Philippines', 'A'),
(118, 'Angeles', 'Pampanga', 'Philippines', 'A'),
(119, 'Mabalacat', 'Pampanga', 'Philippines', 'A'),
(120, 'San Fernando', 'Pampanga', 'Philippines', 'A'),
(121, 'Alaminos', 'Pangasinan', 'Philippines', 'A'),
(122, 'Dagupan', 'Pangasinan', 'Philippines', 'A'),
(123, 'San Carlos', 'Pangasinan', 'Philippines', 'A'),
(124, 'Urdaneta', 'Pangasinan', 'Philippines', 'A'),
(125, 'Lucena', 'Quezon', 'Philippines', 'A'),
(126, 'Tayabas', 'Quezon', 'Philippines', 'A'),
(127, 'Antipolo', 'Rizal', 'Philippines', 'A'),
(128, 'Calbayog', 'Samar (Western)', 'Philippines', 'A'),
(129, 'Catbalogan', 'Samar (Western)', 'Philippines', 'A'),
(130, 'Sorsogon City', 'Sorsogon', 'Philippines', 'A'),
(131, 'General Santos', 'South Cotabato', 'Philippines', 'A'),
(132, 'Koronadal', 'South Cotabato', 'Philippines', 'A'),
(133, 'Maasin', 'Southern Leyte', 'Philippines', 'A'),
(134, 'Tacurong', 'Sultan Kudarat', 'Philippines', 'A'),
(135, 'Surigao City', 'Surigao del Norte', 'Philippines', 'A'),
(136, 'Bislig', 'Surigao del Sur', 'Philippines', 'A'),
(137, 'Tandag', 'Surigao del Sur', 'Philippines', 'A'),
(138, 'Tarlac City', 'Tarlac', 'Philippines', 'A'),
(139, 'Olongapo', 'Zambales', 'Philippines', 'A'),
(140, 'Dapitan', 'Zamboanga del Norte', 'Philippines', 'A'),
(141, 'Dipolog', 'Zamboanga del Norte', 'Philippines', 'A'),
(142, 'Pagadian', 'Zamboanga del Sur', 'Philippines', 'A'),
(143, 'Zamboanga City', 'Zamboanga del Sur', 'Philippines', 'A'),
(144, 'Cordova', 'Cebu', 'Philippines', 'A'),
(145, 'Sibonga', 'Cebu', 'Philippines', 'A');

-- branches (1 rows)
INSERT INTO `branches` (`pkey`, `name`, `description`, `Deleted`, `createdby`, `date_created`, `modifiedby`, `date_modified`) VALUES
(1, 'Main Branch', 'Default branch', 0, 'system', '2026-05-26 09:28:41', NULL, NULL);

-- ic_divisions (1 rows)
INSERT INTO `ic_divisions` (`pkey`, `name`, `datecreated`, `datelastmodified`, `status`) VALUES
(1, 'General Division', '2026-05-26 09:28:41', NULL, 'A');

-- ic_segments (1 rows)
INSERT INTO `ic_segments` (`pkey`, `name`, `datecreated`, `datelastmodified`, `status`) VALUES
(1, 'General Segment', '2026-05-26 09:28:41', NULL, 'A');

-- rate_code (4 rows)
INSERT INTO `rate_code` (`rc_id`, `rc_code`, `rc_name`, `created_at`) VALUES
(1, 'A', 'RESIDENTIAL REGULAR / INSTITUTIONAL', '2026-05-27 21:18:28'),
(2, 'A', 'RESIDENTIAL ASSOCIATE / INSTITUTIONAL', '2026-05-27 21:18:28'),
(5, 'B', 'COMMERCIAL REGULAR / INSTITUTIONAL / INDUSTRIAL', '2026-07-31 15:36:58'),
(6, 'B', 'COMMERCIAL ASSOCIATE / INSTITUTIONAL / INDUSTRIAL', '2026-07-31 15:37:22');

-- water_pipe_sizes (7 rows)
INSERT INTO `water_pipe_sizes` (`pipe_size_id`, `size_code`, `size_label`, `diameter_inches`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'UNSPECIFIED', 'Unspecified / Existing', NULL, 1, 'system', '2026-07-27 17:09:00', '2026-07-27 17:09:00'),
(2, '1/2', '1/2 inch', '0.5000', 1, 'system', '2026-07-27 17:09:00', '2026-07-31 18:02:50'),
(3, '3/4', '3/4 inch', '0.7500', 1, 'system', '2026-07-27 17:09:00', '2026-07-27 17:09:00'),
(4, 1, '1 inch', '1.0000', 1, 'system', '2026-07-27 17:09:00', '2026-07-27 17:09:00'),
(5, '1-1/4', '1-1/4 inches', '1.2500', 1, 'system', '2026-07-27 17:09:00', '2026-07-27 17:09:00'),
(6, '1-1/2', '1-1/2 inches', '1.5000', 1, 'system', '2026-07-27 17:09:00', '2026-07-27 17:09:00'),
(7, 2, '2 inches', '2.0000', 1, 'system', '2026-07-27 17:09:00', '2026-07-27 17:09:00');

-- water_meter_members (52 rows)
INSERT INTO `water_meter_members` (`wmm_id`, `rc_id`, `pipe_size_id`, `bill_code_id`, `from_cb`, `to_cb`, `amount`, `bill_type`, `created_by`, `created_at`, `updated_at`) VALUES
(7, 1, 2, NULL, 0, 5, '144.5000', 'FIXED', 'system', '2026-07-31 15:41:40', '2026-07-31 15:41:40'),
(8, 1, 2, NULL, 6, 10, '30.8000', 'VARIABLE', 'system', '2026-07-31 15:41:40', '2026-07-31 15:41:40'),
(9, 1, 2, NULL, 11, 20, '33.3000', 'VARIABLE', 'system', '2026-07-31 15:41:40', '2026-07-31 15:41:40'),
(10, 1, 2, NULL, 21, 30, '43.8000', 'VARIABLE', 'system', '2026-07-31 15:41:40', '2026-07-31 15:41:40'),
(11, 1, 2, NULL, 31, 40, '49.1000', 'VARIABLE', 'system', '2026-07-31 15:41:40', '2026-07-31 15:41:40'),
(12, 1, 2, NULL, 41, NULL, '54.4000', 'VARIABLE', 'system', '2026-07-31 15:41:40', '2026-07-31 15:41:40'),
(13, 2, 2, NULL, 0, 5, '145.5000', 'FIXED', 'system', '2026-07-31 15:43:31', '2026-07-31 15:43:31'),
(14, 2, 2, NULL, 6, 10, '31.8000', 'VARIABLE', 'system', '2026-07-31 15:43:31', '2026-07-31 15:43:31'),
(15, 2, 2, NULL, 11, 20, '34.3000', 'VARIABLE', 'system', '2026-07-31 15:43:31', '2026-07-31 15:43:31'),
(16, 2, 2, NULL, 21, 30, '44.8000', 'VARIABLE', 'system', '2026-07-31 15:43:31', '2026-07-31 15:43:31'),
(17, 2, 2, NULL, 31, 40, '50.1000', 'VARIABLE', 'system', '2026-07-31 15:43:31', '2026-07-31 15:43:31'),
(18, 2, 2, NULL, 41, NULL, '55.4000', 'VARIABLE', 'system', '2026-07-31 15:43:31', '2026-07-31 15:43:31'),
(19, 6, 2, NULL, 0, 15, '831.0000', 'FIXED', 'system', '2026-07-31 15:45:45', '2026-07-31 15:45:45'),
(20, 6, 2, NULL, 16, 30, '69.2000', 'VARIABLE', 'system', '2026-07-31 15:45:45', '2026-07-31 15:45:45'),
(21, 6, 2, NULL, 31, 500, '83.0000', 'VARIABLE', 'system', '2026-07-31 15:45:45', '2026-07-31 15:45:45'),
(22, 6, 2, NULL, 501, 1000, '93.0000', 'VARIABLE', 'system', '2026-07-31 15:45:45', '2026-07-31 15:45:45'),
(23, 6, 2, NULL, 1001, NULL, '103.0000', 'VARIABLE', 'system', '2026-07-31 15:45:45', '2026-07-31 15:45:45'),
(24, 6, 3, NULL, 0, 15, '1329.0000', 'FIXED', 'system', '2026-07-31 15:47:44', '2026-07-31 15:47:44'),
(25, 6, 3, NULL, 16, 30, '110.7000', 'VARIABLE', 'system', '2026-07-31 15:47:44', '2026-07-31 15:47:44'),
(26, 6, 3, NULL, 31, 500, '132.9000', 'VARIABLE', 'system', '2026-07-31 15:47:44', '2026-07-31 15:47:44'),
(27, 6, 3, NULL, 501, 1000, '142.9000', 'VARIABLE', 'system', '2026-07-31 15:47:44', '2026-07-31 15:47:44'),
(28, 6, 3, NULL, 1001, NULL, '152.9000', 'VARIABLE', 'system', '2026-07-31 15:47:44', '2026-07-31 15:47:44'),
(29, 6, 4, NULL, 0, 15, '2658.8000', 'FIXED', 'system', '2026-07-31 15:51:15', '2026-07-31 15:51:15'),
(30, 6, 4, NULL, 16, 30, '221.4000', 'VARIABLE', 'system', '2026-07-31 15:51:15', '2026-07-31 15:51:15'),
(31, 6, 4, NULL, 31, 500, '265.7000', 'VARIABLE', 'system', '2026-07-31 15:51:15', '2026-07-31 15:51:15'),
(32, 6, 4, NULL, 501, 1000, '275.7000', 'VARIABLE', 'system', '2026-07-31 15:51:15', '2026-07-31 15:51:15'),
(33, 6, 4, NULL, 1001, NULL, '285.7000', 'VARIABLE', 'system', '2026-07-31 15:51:15', '2026-07-31 15:51:15'),
(34, 6, 6, NULL, 0, 15, '6643.6000', 'FIXED', 'system', '2026-07-31 16:11:02', '2026-07-31 16:11:02'),
(35, 6, 6, NULL, 16, 30, '553.6000', 'VARIABLE', 'system', '2026-07-31 16:11:02', '2026-07-31 16:11:02'),
(36, 6, 6, NULL, 31, 500, '664.3000', 'VARIABLE', 'system', '2026-07-31 16:11:02', '2026-07-31 16:11:02'),
(37, 6, 6, NULL, 501, 1000, '674.3000', 'VARIABLE', 'system', '2026-07-31 16:11:02', '2026-07-31 16:11:02'),
(38, 6, 6, NULL, 1001, NULL, '684.3000', 'VARIABLE', 'system', '2026-07-31 16:11:02', '2026-07-31 16:11:02'),
(39, 5, 2, NULL, 0, 15, '830.0000', 'FIXED', 'system', '2026-07-31 16:41:20', '2026-07-31 16:41:20'),
(40, 5, 2, NULL, 16, 30, '68.2000', 'VARIABLE', 'system', '2026-07-31 16:41:20', '2026-07-31 16:41:20'),
(41, 5, 2, NULL, 31, 500, '82.0000', 'VARIABLE', 'system', '2026-07-31 16:41:20', '2026-07-31 16:41:20'),
(42, 5, 2, NULL, 501, 1000, '92.0000', 'VARIABLE', 'system', '2026-07-31 16:41:20', '2026-07-31 16:41:20'),
(43, 5, 2, NULL, 1001, NULL, '102.0000', 'VARIABLE', 'system', '2026-07-31 16:41:20', '2026-07-31 16:41:20'),
(49, 5, 3, NULL, 0, 15, '1328.7000', 'FIXED', 'system', '2026-07-31 16:52:33', '2026-07-31 16:52:33'),
(50, 5, 3, NULL, 16, 30, '109.7000', 'VARIABLE', 'system', '2026-07-31 16:52:33', '2026-07-31 16:52:33'),
(51, 5, 3, NULL, 31, 500, '131.9000', 'VARIABLE', 'system', '2026-07-31 16:52:33', '2026-07-31 16:52:33'),
(52, 5, 3, NULL, 501, 1000, '141.9000', 'VARIABLE', 'system', '2026-07-31 16:52:33', '2026-07-31 16:52:33'),
(53, 5, 3, NULL, 1001, NULL, '151.9000', 'VARIABLE', 'system', '2026-07-31 16:52:33', '2026-07-31 16:52:33'),
(54, 5, 4, NULL, 0, 15, '2657.6000', 'FIXED', 'system', '2026-07-31 16:53:53', '2026-07-31 16:53:53'),
(55, 5, 4, NULL, 16, 30, '220.4000', 'VARIABLE', 'system', '2026-07-31 16:53:53', '2026-07-31 16:53:53'),
(56, 5, 4, NULL, 31, 500, '264.7000', 'VARIABLE', 'system', '2026-07-31 16:53:53', '2026-07-31 16:53:53'),
(57, 5, 4, NULL, 501, 1000, '274.7000', 'VARIABLE', 'system', '2026-07-31 16:53:53', '2026-07-31 16:53:53'),
(58, 5, 4, NULL, 1001, NULL, '284.7000', 'VARIABLE', 'system', '2026-07-31 16:53:53', '2026-07-31 16:53:53'),
(64, 5, 6, NULL, 0, 15, '6643.6000', 'FIXED', 'system', '2026-09-04 15:42:39', '2026-09-04 15:42:39'),
(65, 5, 6, NULL, 16, 30, '553.6000', 'VARIABLE', 'system', '2026-09-04 15:42:39', '2026-09-04 15:42:39'),
(66, 5, 6, NULL, 31, 500, '664.3000', 'VARIABLE', 'system', '2026-09-04 15:42:39', '2026-09-04 15:42:39'),
(67, 5, 6, NULL, 501, 1000, '764.3000', 'VARIABLE', 'system', '2026-09-04 15:42:39', '2026-09-04 15:42:39'),
(68, 5, 6, NULL, 1001, NULL, '864.3000', 'VARIABLE', 'system', '2026-09-04 15:42:39', '2026-09-04 15:42:39');

-- discounts (2 rows)
INSERT INTO `discounts` (`discount_id`, `discount_type`, `is_active`, `water_discount_active`, `water_discount_method`, `water_discount_value`, `water_max_discount_amount`, `water_max_discount_cubic`, `one_time_discount_active`, `one_time_discount_method`, `one_time_discount_value`, `one_time_max_discount_amount`, `installment_discount_active`, `installment_discount_method`, `installment_discount_value`, `installment_max_discount_amount`, `recurring_discount_active`, `recurring_discount_method`, `recurring_discount_value`, `recurring_max_discount_amount`, `created_at`, `updated_at`) VALUES
(1, 'Senior Citizen', 1, 1, 'percent', '5.00', '100.00', NULL, 0, 'none', '0.00', NULL, 0, 'none', '0.00', NULL, 0, 'none', '0.00', NULL, '2026-06-08 11:27:51', '2026-06-08 11:27:51'),
(2, 'Employees', 1, 1, 'free_cubic', '10.00', NULL, NULL, 0, 'none', '0.00', NULL, 0, 'none', '0.00', NULL, 0, 'none', '0.00', NULL, '2026-07-27 19:40:04', '2026-07-31 18:37:18');

-- discount_privilege_rules (2 rows)
INSERT INTO `discount_privilege_rules` (`discount_rule_id`, `discount_id`, `bill_code_id`, `module_context`, `discount_method`, `discount_value`, `max_discount_amount`, `max_discount_cubic`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 2, 1, 'water', 'free_cubic', '10.00', NULL, 10, 1, '2026-05-27 21:32:08', '2026-07-27 19:44:19'),
(2, 1, 1, 'water', 'percent', '5.00', '100.00', NULL, 1, '2026-05-27 21:32:08', '2026-07-27 19:44:19');

-- water_meter_discounts (1 rows)
INSERT INTO `water_meter_discounts` (`wmdiscount_id`, `wmd_name`, `free_water_m3`, `percent_discount`, `fixed_discount`, `active_discount`, `max_m3_for_discount`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Employees', 10, '0.0000', '0.00', 'none', NULL, 'system', '2026-05-27 21:32:08', '2026-05-27 21:32:08');


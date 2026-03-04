-- Database: `bike_management_system'

-- Table structure for table `users`

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff') DEFAULT 'staff',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- Dumping data for table `users`

INSERT INTO `users` (`id`, `username`, `password`, `role`, `created_at`) VALUES
(1, 'Pradeep', '0192023a7bbd73250516f069df18b500', 'admin', '2026-03-01 15:44:57'),
(2, 'Monu', 'de9bf5643eabf80f4a56fda3bbb84483', 'staff', '2026-03-01 15:44:57');

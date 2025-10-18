CREATE TABLE task_submissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  submitter_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  note TEXT NULL,
  file_path VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  mime VARCHAR(128) NULL,
  size_bytes INT UNSIGNED NULL,
  status ENUM('pending','revision_required','approved') NOT NULL DEFAULT 'pending',
  review_comment TEXT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_task_submissions_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_submissions_submitter FOREIGN KEY (submitter_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_submissions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_task_submissions_task_version ON task_submissions(task_id, version);

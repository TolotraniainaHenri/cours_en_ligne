-- Données d'exemple (facultatif) : un cours d'anglais avec exercices.
-- Usage : mysql -u root -p formation_en_ligne < database/seed.sql
-- Le premier admin se crée avec : php backend/bin/create-admin.php

USE formation_en_ligne;

INSERT INTO cours (titre, description, langue, publie) VALUES
  ('English Grammar - Basics', 'Les bases de la grammaire anglaise : verbe to be, présent simple, articles.', 'en', 1),
  ('Grammaire française - Les accords', 'Accord du participe passé, accord de l''adjectif et du pluriel.', 'fr', 1);

INSERT INTO module (id_cours, titre, ordre) VALUES
  (1, 'The verb "to be"', 1),
  (1, 'Present simple', 2),
  (2, 'Les accords', 1);

INSERT INTO lecon (id_module, titre, url_contenu, duree) VALUES
  (1, 'Affirmative form', NULL, 10),
  (1, 'Negative and questions', NULL, 12),
  (2, 'Present simple: he / she / it', NULL, 15),
  (3, 'Accord du participe passé', NULL, 20);

INSERT INTO exercice (id_lecon, question, type, options, bonne_reponse, explication) VALUES
  (1, 'She ___ a teacher.', 'qcm', '["am","is","are"]', 'is', 'Avec he / she / it, on utilise "is".'),
  (1, 'I ___ a student.', 'texte_a_trous', NULL, 'am', 'Avec "I", on utilise "am".'),
  (2, 'Complétez : They ___ not at home.', 'reponse_courte', NULL, 'are', 'Avec they / we / you, on utilise "are".'),
  (3, 'He ___ (to work) every day.', 'reponse_courte', NULL, 'works', 'À la 3e personne du singulier, on ajoute -s au verbe.'),
  (4, 'Les fleurs que j''ai ___ (cueillir) sont belles.', 'reponse_courte', NULL, 'cueillies', 'Le COD "que" (les fleurs) est placé avant : le participe s''accorde au féminin pluriel.');

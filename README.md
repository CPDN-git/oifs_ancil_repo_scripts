# oifs_ancil_repo_scripts
Scripts for handling upload of initial files to the repository

## Site configuration

The deployed PHP pages load `html/user/cpdn/include/batch_site_config.inc`
before BOINC libraries. It must provide the host-specific `$project_path`;
the upload handler also uses `$python_env` and `$oifs_path` for its backend.
The configuration file is managed on the server and is not deployed by this repo.

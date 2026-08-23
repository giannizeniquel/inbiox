// Production environment values.
//
// These are placeholders. They MUST be reviewed/overridden with the real
// production domains before this build is deployed (e.g. via a build-time
// substitution step in CI/CD, or by editing this file for the actual deploy).
export const environment = {
  production: true,
  // TODO(deploy): set to the real production API base URL.
  apiUrl: 'https://api.inbiox.com',
  // TODO(deploy): set to the real production Mercure hub URL.
  mercureUrl: 'https://mercure.inbiox.com'
};

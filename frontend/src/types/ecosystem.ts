export interface EcosystemContext {
  deployment_mode: "standalone" | "saas";
  identity: {
    tenant_id: string;
    user_id: string;
    user_name: string;
    role: string;
    mode: "standalone" | "saas";
    is_sso: boolean;
  };
  active_drivers: {
    identity: string;
    telematics: string;
    fleet: string;
    accounting: string;
  };
  integrations: {
    optinexus: boolean;
    optiradar: boolean;
    optifleet: boolean;
    optientry: boolean;
  };
}

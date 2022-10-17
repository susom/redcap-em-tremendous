# Tremendous
Tremendous (https://www.tremendous.com) is a vendor which manages Gift Card distribution. There are 2 ways to send gift cards
to your participates, either through manual entry within the Tremendous website or programatically through API calls.
This EM handles the programatic interaction with Tremendous through API calls.

Before this module can be used, a Tremendous account must be created and setup with payment information. Once that is accomplished,
an API Token must be generated within Tremendous and stored in the REDCap EM Project.  Also, once payment is setup, a Tremendous Funding ID
must be added to the EM Configuration File.

## How it works
Each time a record is saved, the EM will check to see if this record meets the criteria for a gift card reward. If it does,
and a gift card request has not been sent, it will create an Order Request to Tremendous to send a gift card to
the participant.  The gift card can be sent via Email or SMS. When the request is valid, Tremendous will take over
and handle the sending of the gift card to the participant.

## Which Gift Cards to use
Tremendous offers a wide range of gift cards and allows users to select the type of reward that suits them best. When
setting up the Tremendous account, the provider is allowed to choose which options to give the recipients. In the
Tremendous website, you have the option to select Prepaid Cards (such as Visa), merchant gift cards (such as Amazon),
Charity Donations (such as American Red Cross), Paypal payment or a bank transfer.

This module allows you to choose a handful of options (by entering the brand IDs directly), or you can create a Tremendous
Campaign.  A Tremendous Campaign will allow you to select a variety of options of rewards and each person in that
campaign will have the same option list.  A campaign also tags the request so you know which requests were part of the
campaign.

## Reporting
When requests are made, there is the ability to add custom data to the request to help with reporting. The EM submits
REDCap project ID, record ID and Reward Configuration Name with the request.  This will allow easy reporting for each
study.

## REDCap record management
There are three required fields in the REDCap project for each reward. These fields need to be in the same event as the
reward logic.  The fields are reward status, reward message and reward timestamp.  The reward status will only be
filled in once an order request to Tremendous is successful. The reward message field will hold any information pertaining
to the order request that might be useful.  For instance, if the request was unsuccessful. The timestamp is filled
when the status or a message is saved.
